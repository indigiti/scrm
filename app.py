"""SchoolFlow CRM — small deployable multi-tenant FastAPI application.

SQLite is intentional for an easy single-server installation; use PostgreSQL,
managed secrets, background jobs, and a security review before large production use.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import re
import secrets
import sqlite3
import sys
import time
from contextlib import contextmanager
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Annotated, Literal

from fastapi import Depends, FastAPI, Header, HTTPException, Request, Response
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field

ROOT = Path(__file__).resolve().parent
DB_PATH = Path(os.getenv('SCHOOLFLOW_DB', str(ROOT / 'schoolflow.sqlite3')))
STAGES = ('New', 'Contacted', 'Counselling', 'Visit Scheduled', 'Applied', 'Enrolled', 'Lost')
ROLES = ('Admin', 'Counsellor', 'Admissions')
SESSION_SECONDS = 12 * 60 * 60
MAX_STRING = 250
RATE: dict[str, list[float]] = {}
app = FastAPI(title='SchoolFlow CRM API', version='1.0.0', docs_url=None, redoc_url=None)
app.mount('/static', StaticFiles(directory=str(ROOT / 'static')), name='static')


@app.middleware('http')
async def add_security_headers(request: Request, call_next):
    response = await call_next(request)
    response.headers['X-Content-Type-Options'] = 'nosniff'
    response.headers['X-Frame-Options'] = 'DENY'
    response.headers['Referrer-Policy'] = 'same-origin'
    response.headers['Content-Security-Policy'] = (
        "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        "img-src 'self' data:; connect-src 'self'; object-src 'none'; "
        "frame-ancestors 'none'; base-uri 'none'; form-action 'self'"
    )
    if request.url.path.startswith('/api/'):
        response.headers['Cache-Control'] = 'no-store'
    return response


def utcnow():
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def normalize_phone(phone: str) -> str:
    digits = re.sub(r'\D+', '', phone)
    # Normalise common Indian local/E.164 formats without collapsing arbitrary countries.
    return digits[-10:] if len(digits) == 12 and digits.startswith('91') else digits[-12:]


def digest(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


def password_hash(password: str, salt: str) -> str:
    return hashlib.pbkdf2_hmac('sha256', password.encode(), bytes.fromhex(salt), 420_000).hex()


def clean_string(value: str | None, max_length=MAX_STRING) -> str:
    return str(value or '').strip()[:max_length]


@contextmanager
def db():
    conn = sqlite3.connect(DB_PATH, timeout=15)
    conn.row_factory = sqlite3.Row
    conn.execute('PRAGMA foreign_keys=ON')
    conn.execute('PRAGMA busy_timeout=15000')
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def init_db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    with db() as c:
        c.execute('PRAGMA journal_mode=WAL')
        c.executescript('''
        CREATE TABLE IF NOT EXISTS schools (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT NOT NULL,
          created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS users (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT NOT NULL,
          email TEXT NOT NULL UNIQUE COLLATE NOCASE,
          salt TEXT NOT NULL,
          password_hash TEXT NOT NULL,
          active INTEGER NOT NULL DEFAULT 1,
          created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS memberships (
          user_id INTEGER NOT NULL REFERENCES users(id),
          school_id INTEGER NOT NULL REFERENCES schools(id),
          role TEXT NOT NULL CHECK(role IN ('Admin','Counsellor','Admissions')),
          PRIMARY KEY(user_id, school_id)
        );
        CREATE TABLE IF NOT EXISTS sessions (
          token_hash TEXT PRIMARY KEY,
          user_id INTEGER NOT NULL REFERENCES users(id),
          expires_at TEXT NOT NULL,
          created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS sites (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          school_id INTEGER NOT NULL REFERENCES schools(id),
          name TEXT NOT NULL,
          domain TEXT NOT NULL,
          platform TEXT NOT NULL,
          key_hash TEXT NOT NULL UNIQUE,
          enabled INTEGER NOT NULL DEFAULT 1,
          created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS leads (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          school_id INTEGER NOT NULL REFERENCES schools(id),
          site_id INTEGER REFERENCES sites(id),
          name TEXT NOT NULL,
          guardian TEXT NOT NULL,
          phone TEXT NOT NULL,
          normalized_phone TEXT NOT NULL,
          email TEXT NOT NULL DEFAULT '',
          grade TEXT NOT NULL DEFAULT '',
          message TEXT NOT NULL DEFAULT '',
          utm_source TEXT NOT NULL DEFAULT '',
          utm_campaign TEXT NOT NULL DEFAULT '',
          status TEXT NOT NULL DEFAULT 'New',
          assigned_to INTEGER REFERENCES users(id),
          followup_date TEXT NOT NULL DEFAULT '',
          consent INTEGER NOT NULL DEFAULT 0,
          duplicate_of INTEGER REFERENCES leads(id),
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_leads_school ON leads(school_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads(school_id, normalized_phone);
        CREATE INDEX IF NOT EXISTS idx_leads_owner ON leads(school_id, assigned_to);
        CREATE TABLE IF NOT EXISTS activities (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          school_id INTEGER NOT NULL REFERENCES schools(id),
          lead_id INTEGER NOT NULL REFERENCES leads(id),
          actor_id INTEGER REFERENCES users(id),
          action TEXT NOT NULL,
          details TEXT NOT NULL DEFAULT '',
          created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_activities_lead ON activities(lead_id, created_at DESC);
        CREATE TABLE IF NOT EXISTS webhook_receipts (
          site_id INTEGER NOT NULL REFERENCES sites(id),
          idempotency_key TEXT NOT NULL,
          lead_id INTEGER NOT NULL REFERENCES leads(id),
          created_at TEXT NOT NULL,
          PRIMARY KEY(site_id, idempotency_key)
        );
        CREATE TABLE IF NOT EXISTS audits (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          school_id INTEGER NOT NULL REFERENCES schools(id),
          actor_id INTEGER REFERENCES users(id),
          action TEXT NOT NULL,
          subject TEXT NOT NULL,
          created_at TEXT NOT NULL
        );
        ''')


def make_admin(email: str, password: str, name: str, school: str):
    if len(password) < 12:
        raise ValueError('Admin password must have at least 12 characters')
    init_db()
    with db() as c:
        if c.execute('SELECT 1 FROM users').fetchone():
            raise ValueError('Bootstrap is only allowed with an empty user database')
        now = utcnow()
        salt = secrets.token_hex(16)
        cur = c.execute('INSERT INTO users (name,email,salt,password_hash,created_at) VALUES (?,?,?,?,?)',
                        (name, email.lower().strip(), salt, password_hash(password, salt), now))
        user_id = cur.lastrowid
        cur = c.execute('INSERT INTO schools (name,created_at) VALUES (?,?)', (school, now))
        c.execute('INSERT INTO memberships (user_id,school_id,role) VALUES (?, ?, ?)', (user_id, cur.lastrowid, 'Admin'))
    return True


@app.on_event('startup')
def startup():
    init_db()


def forbid(msg='Permission denied', code=403):
    raise HTTPException(status_code=code, detail=msg)


def member_session(authorization: Annotated[str | None, Header()] = None):
    if not authorization or not authorization.startswith('Bearer '):
        raise HTTPException(status_code=401, detail='Sign in required')
    token = authorization[7:]
    if len(token) < 24:
        raise HTTPException(status_code=401, detail='Invalid session')
    with db() as c:
        row = c.execute('''SELECT u.id,u.name,u.email,s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id
                           WHERE s.token_hash=? AND u.active=1''', (digest(token),)).fetchone()
    if not row or row['expires_at'] <= utcnow():
        raise HTTPException(status_code=401, detail='Session expired; sign in again')
    return dict(row)


def membership(school_id: int, user: dict, *roles: str) -> str:
    with db() as c:
        row = c.execute('SELECT role FROM memberships WHERE school_id=? AND user_id=?', (school_id, user['id'])).fetchone()
    if not row:
        forbid('You are not a member of this school')
    if roles and row['role'] not in roles:
        forbid('Your role does not permit this action')
    return row['role']


def limiter(key: str, maximum: int, window_seconds: int):
    # Best-effort local limiter, not a replacement for a reverse-proxy limiter.
    now = time.monotonic()
    seen = RATE.get(key, [])
    seen = [t for t in seen if now - t < window_seconds]
    if len(seen) >= maximum:
        RATE[key] = seen
        raise HTTPException(status_code=429, detail='Too many requests. Try again later.')
    seen.append(now)
    RATE[key] = seen
    if len(RATE) > 5000:
        for k in list(RATE)[:1000]:
            del RATE[k]


class LoginRequest(BaseModel):
    email: str = Field(min_length=3, max_length=254)
    password: str = Field(min_length=1, max_length=1024)


class SchoolCreate(BaseModel):
    name: str = Field(min_length=3, max_length=140)


class SiteCreate(BaseModel):
    name: str = Field(min_length=2, max_length=140)
    domain: str = Field(min_length=3, max_length=180)
    platform: str = Field(default='WordPress', max_length=60)


class StaffCreate(BaseModel):
    name: str = Field(min_length=2, max_length=140)
    email: str = Field(min_length=3, max_length=254)
    password: str = Field(default='', max_length=1024)
    role: Literal['Admin', 'Counsellor', 'Admissions']


class LeadCreate(BaseModel):
    student_name: str = Field(min_length=2, max_length=140)
    parent_name: str = Field(min_length=2, max_length=140)
    phone: str = Field(min_length=6, max_length=40)
    grade: str = Field(default='', max_length=80)
    email: str = Field(default='', max_length=160)
    message: str = Field(default='', max_length=2000)
    site_id: int | None = None
    assigned_to: int | None = None
    followup_date: str = ''
    consent: bool = False


class LeadPatch(BaseModel):
    status: Literal['New', 'Contacted', 'Counselling', 'Visit Scheduled', 'Applied', 'Enrolled', 'Lost'] | None = None
    assigned_to: int | None = None
    followup_date: str | None = None


class NoteCreate(BaseModel):
    text: str = Field(min_length=1, max_length=2000)


class InboundLead(BaseModel):
    student_name: str = Field(min_length=2, max_length=140)
    parent_name: str = Field(min_length=2, max_length=140)
    phone: str = Field(min_length=6, max_length=40)
    grade: str = Field(default='', max_length=80, alias='class')
    email: str = Field(default='', max_length=160)
    message: str = Field(default='', max_length=2000)
    utm_source: str = Field(default='', max_length=140)
    utm_campaign: str = Field(default='', max_length=140)
    consent: bool

    model_config = {'populate_by_name': True, 'extra': 'ignore'}


def valid_date(value: str | None):
    if value:
        try:
            date.fromisoformat(value)
        except ValueError:
            raise HTTPException(status_code=422, detail='Date must be YYYY-MM-DD')
    return value or ''


def validated_phone(value: str):
    if len(re.sub(r'\D', '', value)) < 7:
        raise HTTPException(status_code=422, detail='Phone number is too short')
    return clean_string(value, 40)


def assignment(c: sqlite3.Connection, school_id: int, user_id: int | None):
    if user_id is None:
        return None
    exists = c.execute('SELECT 1 FROM memberships WHERE school_id=? AND user_id=?', (school_id, user_id)).fetchone()
    if not exists:
        raise HTTPException(status_code=422, detail='Assigned staff must belong to this school')
    return user_id


def auto_owner(c: sqlite3.Connection, school_id: int):
    # Choose counsellor with fewest currently open leads.
    row = c.execute('''SELECT m.user_id, COUNT(l.id) AS cnt FROM memberships m
                       LEFT JOIN leads l ON l.assigned_to=m.user_id AND l.school_id=m.school_id
                           AND l.status NOT IN ('Enrolled','Lost')
                       WHERE m.school_id=? AND m.role='Counsellor'
                       GROUP BY m.user_id ORDER BY cnt, m.user_id LIMIT 1''', (school_id,)).fetchone()
    return row['user_id'] if row else None


def record(c, school_id, lead_id, actor_id, action, details=''):
    now = utcnow()
    c.execute('INSERT INTO activities(school_id,lead_id,actor_id,action,details,created_at) VALUES (?,?,?,?,?,?)',
              (school_id, lead_id, actor_id, action, details[:2000], now))
    c.execute('INSERT INTO audits(school_id,actor_id,action,subject,created_at) VALUES(?,?,?,?,?)',
              (school_id, actor_id, action, f'lead:{lead_id}', now))


def create_lead(c, school_id, p, site_id=None, actor_id=None, assigned_to=None, source_action='created'):
    now = utcnow()
    phone = validated_phone(p.phone)
    norm = normalize_phone(phone)
    duplicate = c.execute('SELECT id FROM leads WHERE school_id=? AND normalized_phone=? ORDER BY id LIMIT 1',
                          (school_id, norm)).fetchone()
    if assigned_to is None:
        assigned_to = auto_owner(c, school_id)
    cur = c.execute('''INSERT INTO leads(school_id,site_id,name,guardian,phone,normalized_phone,email,grade,message,
            utm_source,utm_campaign,status,assigned_to,followup_date,consent,duplicate_of,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',
            (school_id,site_id,clean_string(p.student_name,140),clean_string(p.parent_name,140),phone,norm,
             clean_string(p.email,160),clean_string(p.grade,80),clean_string(p.message,2000),
             clean_string(getattr(p,'utm_source',''),140),clean_string(getattr(p,'utm_campaign',''),140),
             'New',assigned_to,valid_date(getattr(p,'followup_date','')),int(p.consent),
             duplicate['id'] if duplicate else None,now,now))
    lead_id = cur.lastrowid
    record(c, school_id, lead_id, actor_id, source_action, 'Potential duplicate' if duplicate else '')
    return lead_id


def lead_dict(row):
    return dict(row)


def lead_query(where=''):
    return ('''SELECT l.*, s.name AS source_name, s.domain AS source_domain, u.name AS assigned_name
           FROM leads l LEFT JOIN sites s ON s.id=l.site_id
           LEFT JOIN users u ON u.id=l.assigned_to ''' + where)


@app.get('/')
def index():
    return FileResponse(ROOT / 'static' / 'index.html')


@app.get('/health')
def health():
    return {'ok': True}


@app.post('/api/auth/login')
def login(payload: LoginRequest, request: Request):
    limiter('login:'+str(request.client.host if request.client else '?'), 12, 900)
    with db() as c:
        row = c.execute('SELECT * FROM users WHERE email=? AND active=1', (payload.email.strip().lower(),)).fetchone()
        # Compare a derived dummy password as well, reducing timing differences.
        if row:
            expected = password_hash(payload.password, row['salt'])
            valid = hmac.compare_digest(expected, row['password_hash'])
        else:
            expected = password_hash(payload.password, '0'*32)
            valid = False
        if not valid:
            raise HTTPException(status_code=401, detail='Incorrect email or password')
        token = secrets.token_urlsafe(40)
        expiry = (datetime.now(timezone.utc) + timedelta(seconds=SESSION_SECONDS)).replace(microsecond=0).isoformat()
        c.execute('INSERT INTO sessions(token_hash,user_id,expires_at,created_at) VALUES(?,?,?,?)',
                  (digest(token), row['id'], expiry, utcnow()))
        c.execute('DELETE FROM sessions WHERE expires_at < ?', (utcnow(),))
    return {'token': token, 'expires_at': expiry}


@app.post('/api/auth/logout')
def logout(authorization: Annotated[str | None, Header()] = None, user=Depends(member_session)):
    with db() as c:
        c.execute('DELETE FROM sessions WHERE token_hash=?', (digest(authorization[7:]),))
    return {'ok': True}


@app.get('/api/me')
def me(user=Depends(member_session)):
    with db() as c:
        schools = [dict(r) for r in c.execute('''SELECT s.id,s.name,m.role FROM memberships m
                JOIN schools s ON s.id=m.school_id WHERE m.user_id=? ORDER BY s.name''', (user['id'],))]
    return {'user': {'id':user['id'],'name':user['name'],'email':user['email']}, 'schools':schools}


@app.post('/api/schools', status_code=201)
def create_school(payload: SchoolCreate, user=Depends(member_session)):
    with db() as c:
        if not c.execute("SELECT 1 FROM memberships WHERE user_id=? AND role='Admin'", (user['id'],)).fetchone():
            forbid('Only an existing school admin can create another school')
        cur = c.execute('INSERT INTO schools(name,created_at) VALUES(?,?)', (payload.name.strip(),utcnow()))
        c.execute('INSERT INTO memberships(user_id,school_id,role) VALUES(?,?,?)', (user['id'],cur.lastrowid,'Admin'))
        return {'id':cur.lastrowid,'name':payload.name,'role':'Admin'}


@app.get('/api/schools/{school_id}/members')
def members(school_id: int, user=Depends(member_session)):
    membership(school_id,user)
    with db() as c:
        return [dict(r) for r in c.execute('''SELECT u.id,u.name,u.email,m.role FROM memberships m
             JOIN users u ON u.id=m.user_id WHERE m.school_id=? ORDER BY u.name''',(school_id,))]


@app.post('/api/schools/{school_id}/members', status_code=201)
def add_member(school_id: int, payload: StaffCreate, user=Depends(member_session)):
    membership(school_id,user,'Admin')
    email = payload.email.strip().lower()
    with db() as c:
        existing = c.execute('SELECT id FROM users WHERE email=?',(email,)).fetchone()
        if existing:
            person_id = existing['id']
        else:
            if len(payload.password) < 12:
                raise HTTPException(422, 'New staff password must contain at least 12 characters')
            salt = secrets.token_hex(16)
            cur = c.execute('INSERT INTO users(name,email,salt,password_hash,created_at) VALUES(?,?,?,?,?)',
                (payload.name.strip(),email,salt,password_hash(payload.password,salt),utcnow()))
            person_id=cur.lastrowid
        if c.execute('SELECT 1 FROM memberships WHERE school_id=? AND user_id=?',(school_id,person_id)).fetchone():
            raise HTTPException(409,'This staff member already belongs to this school')
        c.execute('INSERT INTO memberships(user_id,school_id,role) VALUES(?,?,?)', (person_id,school_id,payload.role))
        c.execute('INSERT INTO audits(school_id,actor_id,action,subject,created_at) VALUES(?,?,?,?,?)',
                  (school_id,user['id'],'member_added',f'user:{person_id}',utcnow()))
    return {'id':person_id, 'name':payload.name, 'email':email,'role':payload.role,'existing_account':bool(existing)}


@app.get('/api/schools/{school_id}/sites')
def list_sites(school_id: int, user=Depends(member_session)):
    membership(school_id,user)
    with db() as c:
        return [dict(r) for r in c.execute('''SELECT s.id,s.school_id,s.name,s.domain,s.platform,s.enabled,s.created_at,
             COUNT(l.id) AS lead_count FROM sites s LEFT JOIN leads l ON l.site_id=s.id
             WHERE s.school_id=? GROUP BY s.id ORDER BY s.id DESC''',(school_id,))]


@app.post('/api/schools/{school_id}/sites', status_code=201)
def create_site(school_id: int, payload: SiteCreate, user=Depends(member_session)):
    membership(school_id,user,'Admin')
    token = 'sfsite_'+secrets.token_urlsafe(32)
    with db() as c:
        cur = c.execute('''INSERT INTO sites(school_id,name,domain,platform,key_hash,created_at)
                           VALUES(?,?,?,?,?,?)''',
                        (school_id,payload.name.strip(),payload.domain.strip(),payload.platform.strip(),digest(token),utcnow()))
        c.execute('INSERT INTO audits(school_id,actor_id,action,subject,created_at) VALUES(?,?,?,?,?)',
                  (school_id,user['id'],'site_created',f'site:{cur.lastrowid}',utcnow()))
    return {'id':cur.lastrowid,'name':payload.name,'api_key':token,
            'warning':'Copy this key now. It will not be shown again.'}


@app.post('/api/schools/{school_id}/sites/{site_id}/rotate-key')
def rotate_site_key(school_id: int, site_id: int, user=Depends(member_session)):
    membership(school_id,user,'Admin')
    key='sfsite_'+secrets.token_urlsafe(32)
    with db() as c:
        cur=c.execute('UPDATE sites SET key_hash=? WHERE id=? AND school_id=?', (digest(key),site_id,school_id))
        if not cur.rowcount: raise HTTPException(404,'Website not found')
    return {'api_key':key,'warning':'The old key stopped working. Copy this key now.'}


@app.patch('/api/schools/{school_id}/sites/{site_id}')
def set_site_status(school_id: int, site_id: int, enabled: bool, user=Depends(member_session)):
    membership(school_id,user,'Admin')
    with db() as c:
        cur=c.execute('UPDATE sites SET enabled=? WHERE school_id=? AND id=?',(int(enabled),school_id,site_id))
        if not cur.rowcount: raise HTTPException(404,'Website not found')
    return {'ok':True,'enabled':enabled}


@app.get('/api/schools/{school_id}/leads')
def list_leads(school_id: int, search: str='', status: str='', site_id: int|None=None,
               assigned_to: int|None=None, user=Depends(member_session)):
    role=membership(school_id,user)
    sql=lead_query('WHERE l.school_id=?')
    args=[school_id]
    if role=='Counsellor':
        sql+=' AND l.assigned_to=?'
        args.append(user['id'])
    if search:
        sql+=' AND (l.name LIKE ? OR l.guardian LIKE ? OR l.phone LIKE ? OR l.grade LIKE ?)'
        q='%'+search.strip()[:80]+'%'
        args.extend([q]*4)
    if status and status in STAGES:
        sql+=' AND l.status=?'; args.append(status)
    if site_id is not None:
        sql+=' AND l.site_id=?'; args.append(site_id)
    if assigned_to is not None:
        sql+=' AND l.assigned_to=?'; args.append(assigned_to)
    sql+=' ORDER BY l.id DESC LIMIT 2000'
    with db() as c:
        return [lead_dict(r) for r in c.execute(sql,args)]


@app.post('/api/schools/{school_id}/leads', status_code=201)
def add_lead(school_id: int, p: LeadCreate, user=Depends(member_session)):
    role=membership(school_id,user)
    with db() as c:
        if p.site_id is not None and not c.execute('SELECT 1 FROM sites WHERE school_id=? AND id=?', (school_id,p.site_id)).fetchone():
            raise HTTPException(422,'Website does not belong to this school')
        if role=='Counsellor' and p.assigned_to not in (None,user['id']):
            forbid('Counsellors may only assign enquiries to themselves')
        owner = user['id'] if role=='Counsellor' else (assignment(c,school_id,p.assigned_to) if p.assigned_to else None)
        lead_id = create_lead(c,school_id,p,p.site_id,user['id'],owner,'manual_create')
        row=c.execute(lead_query('WHERE l.id=? AND l.school_id=?'),(lead_id,school_id)).fetchone()
        return lead_dict(row)


def allowed_lead(c, school_id, lead_id, user, role):
    row=c.execute(lead_query('WHERE l.id=? AND l.school_id=?'),(lead_id,school_id)).fetchone()
    if not row: raise HTTPException(404, 'Enquiry not found')
    if role=='Counsellor' and row['assigned_to'] != user['id']:
        forbid('You can only access your assigned enquiries')
    return row


@app.get('/api/schools/{school_id}/leads/{lead_id}')
def get_lead(school_id: int, lead_id: int, user=Depends(member_session)):
    role=membership(school_id,user)
    with db() as c:
        row=allowed_lead(c,school_id,lead_id,user,role)
        activities=[dict(a) for a in c.execute('''SELECT a.*, u.name AS actor_name FROM activities a
              LEFT JOIN users u ON u.id=a.actor_id WHERE a.school_id=? AND a.lead_id=?
              ORDER BY a.id DESC LIMIT 100''', (school_id,lead_id))]
    return {'lead':lead_dict(row),'activities':activities}


@app.patch('/api/schools/{school_id}/leads/{lead_id}')
def update_lead(school_id: int, lead_id: int, payload: LeadPatch, user=Depends(member_session)):
    role=membership(school_id,user)
    changes=payload.model_dump(exclude_unset=True)
    if not changes:
        raise HTTPException(422,'Nothing to update')
    with db() as c:
        old=allowed_lead(c,school_id,lead_id,user,role)
        if role=='Counsellor' and 'assigned_to' in changes and changes['assigned_to'] != user['id']:
            forbid('Counsellors cannot reassign leads')
        if 'assigned_to' in changes:
            assignment(c, school_id, changes['assigned_to'])
        if 'followup_date' in changes:
            changes['followup_date']=valid_date(changes['followup_date'])
        for key,value in changes.items():
            if old[key] != value:
                record(c,school_id,lead_id,user['id'],'update_'+key,f'{old[key] or "(none)"} → {value or "(none)"}')
        columns=', '.join(f'{key}=?' for key in changes)
        c.execute(f'UPDATE leads SET {columns}, updated_at=? WHERE id=? AND school_id=?',
                  [*changes.values(),utcnow(),lead_id,school_id])
        result=c.execute(lead_query('WHERE l.id=? AND l.school_id=?'),(lead_id,school_id)).fetchone()
    return lead_dict(result)


@app.post('/api/schools/{school_id}/leads/{lead_id}/notes', status_code=201)
def add_note(school_id: int, lead_id: int, payload: NoteCreate, user=Depends(member_session)):
    role=membership(school_id,user)
    with db() as c:
        allowed_lead(c,school_id,lead_id,user,role)
        record(c,school_id,lead_id,user['id'],'note',payload.text.strip())
    return {'ok':True}


@app.post('/api/v1/inbound-leads', status_code=201)
def inbound_lead(p: InboundLead, request: Request, authorization: Annotated[str|None, Header()] = None,
                 idempotency_key: Annotated[str|None, Header(alias='Idempotency-Key')] = None):
    if not authorization or not authorization.startswith('Bearer sfsite_'):
        raise HTTPException(401,'Website API key required')
    if not idempotency_key or len(idempotency_key) > 160 or len(idempotency_key.strip()) < 6:
        raise HTTPException(422,'A unique Idempotency-Key of 6–160 characters is required')
    if not p.consent:
        raise HTTPException(422,'The parent/guardian must consent to contact')
    key_hash=digest(authorization[7:])
    with db() as c:
        site=c.execute('SELECT * FROM sites WHERE key_hash=? AND enabled=1',(key_hash,)).fetchone()
        if not site: raise HTTPException(401,'Website key not recognized or inactive')
        limiter('site:'+str(site['id']),120,60)
        duplicate=c.execute('SELECT lead_id FROM webhook_receipts WHERE site_id=? AND idempotency_key=?',
                            (site['id'],idempotency_key)).fetchone()
        if duplicate:
            return Response(content=json.dumps({'id':duplicate['lead_id'],'duplicate_submission':True}),
                            status_code=200, media_type='application/json')
        lead_id=create_lead(c,site['school_id'],p,site['id'],None,None,'website_received')
        try:
            c.execute('INSERT INTO webhook_receipts(site_id,idempotency_key,lead_id,created_at) VALUES(?,?,?,?)',
                    (site['id'],idempotency_key,lead_id,utcnow()))
        except sqlite3.IntegrityError:
            raise HTTPException(409,'Idempotency conflict: retry with same key')
    return {'id':lead_id,'duplicate_submission':False}


if __name__ == '__main__':
    if len(sys.argv)>1 and sys.argv[1]=='create-admin':
        import getpass
        email=input('Admin email: ').strip()
        name=input('Admin full name: ').strip()
        school=input('First school name: ').strip()
        pw=getpass.getpass('Password (minimum 12 characters): ')
        try:
            make_admin(email,pw,name,school)
            print('Admin and first school created. Start server with: uvicorn app:app --host 127.0.0.1 --port 8000')
        except ValueError as e:
            print('Setup error:', e, file=sys.stderr); sys.exit(1)
    else:
        print('Usage: python app.py create-admin')