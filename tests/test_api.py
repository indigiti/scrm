import importlib
import pytest
from fastapi.testclient import TestClient

@pytest.fixture
def setup(tmp_path, monkeypatch):
    monkeypatch.setenv('SCHOOLFLOW_DB', str(tmp_path / 'crm.sqlite3'))
    import app
    importlib.reload(app)
    app.RATE.clear()
    app.make_admin('owner@test.example','AdminSecurePwd#123','Owner','Sunrise School')
    with TestClient(app.app) as client:
        result=client.post('/api/auth/login',json={'email':'owner@test.example','password':'AdminSecurePwd#123'})
        assert result.status_code==200, result.text
        token=result.json()['token']
        headers={'Authorization':'Bearer '+token}
        s1=client.get('/api/me',headers=headers).json()['schools'][0]['id']
        yield client,headers,s1


def test_admin_login_and_site(setup):
    client,h,s1=setup
    r=client.post(f'/api/schools/{s1}/sites',headers=h,json={'name':'Admissions','domain':'school.example','platform':'WordPress'})
    assert r.status_code==201,r.text
    assert r.json()['api_key'].startswith('sfsite_')
    assert 'api_key' not in str(client.get(f'/api/schools/{s1}/sites',headers=h).json())


def test_inbound_idempotency_and_duplicate_phone(setup):
    client,h,s=setup
    key=client.post(f'/api/schools/{s}/sites',headers=h,json={'name':'Site','domain':'site.example'}).json()['api_key']
    headers={'Authorization':'Bearer '+key,'Idempotency-Key':'unique-lead-001'}
    payload={'student_name':'Test Child','parent_name':'Test Parent','phone':'+91 9898989898','class':'Grade 2','consent':True}
    first=client.post('/api/v1/inbound-leads',headers=headers,json=payload)
    assert first.status_code==201,first.text
    again=client.post('/api/v1/inbound-leads',headers=headers,json=payload)
    assert again.status_code==200 and again.json()['id']==first.json()['id']
    second=client.post('/api/v1/inbound-leads',headers={**headers,'Idempotency-Key':'unique-lead-002'},json=payload)
    assert second.status_code==201
    leads=client.get(f'/api/schools/{s}/leads',headers=h).json()
    assert len(leads)==2 and leads[0]['duplicate_of']==leads[1]['id']
    assert client.post('/api/v1/inbound-leads',headers={**headers,'Idempotency-Key':'unique-lead-003'},json={**payload,'consent':False}).status_code==422


def test_tenant_and_role_isolation(setup):
    client,h,s1=setup
    s2=client.post('/api/schools',headers=h,json={'name':'Other Academy'}).json()['id']
    site1=client.post(f'/api/schools/{s1}/sites',headers=h,json={'name':'Main','domain':'main.test'}).json()['id']
    school2lead=client.post(f'/api/schools/{s2}/leads',headers=h,json={'student_name':'Child Two','parent_name':'Parent Two','phone':'9999911111'}).json()['id']
    member=client.post(f'/api/schools/{s1}/members',headers=h,json={'name':'Counsellor','email':'counsellor@test.example','password':'VerySecurePwd#345','role':'Counsellor'})
    assert member.status_code==201,member.text
    counsellor_id=member.json()['id']
    other=client.post(f'/api/schools/{s1}/members',headers=h,json={'name':'Second Counsellor','email':'other@test.example','password':'VerySecurePwd#345','role':'Counsellor'}).json()['id']
    first=client.post(f'/api/schools/{s1}/leads',headers=h,json={'student_name':'Child One','parent_name':'Parent One','phone':'9999922222','assigned_to':counsellor_id,'site_id':site1}).json()['id']
    second=client.post(f'/api/schools/{s1}/leads',headers=h,json={'student_name':'Child Other','parent_name':'Parent Other','phone':'9999933333','assigned_to':other}).json()['id']
    login=client.post('/api/auth/login',json={'email':'counsellor@test.example','password':'VerySecurePwd#345'}).json()['token']
    hc={'Authorization':'Bearer '+login}
    my=client.get(f'/api/schools/{s1}/leads',headers=hc).json()
    assert [r['id'] for r in my]==[first]
    assert client.get(f'/api/schools/{s1}/leads/{second}',headers=hc).status_code==403
    assert client.get(f'/api/schools/{s2}/leads/{school2lead}',headers=hc).status_code==403
    assert client.post(f'/api/schools/{s1}/sites',headers=hc,json={'name':'Bad','domain':'bad.test'}).status_code==403
    assert client.patch(f'/api/schools/{s1}/leads/{first}',headers=hc,json={'assigned_to':other}).status_code==403
    assert client.post(f'/api/schools/{s2}/leads',headers=h,json={'student_name':'Bad Child','parent_name':'Bad Parent','phone':'7777722222','site_id':site1}).status_code==422


def test_site_key_does_not_allow_crm_login(setup):
    client,h,s=setup
    key=client.post(f'/api/schools/{s}/sites',headers=h,json={'name':'Main','domain':'site.example'}).json()['api_key']
    assert client.get('/api/me',headers={'Authorization':'Bearer '+key}).status_code==401
    assert client.post('/api/v1/inbound-leads',headers={'Authorization':'Bearer '+key,'Idempotency-Key':'unique-121'},json={'student_name':'Child','parent_name':'Parent','phone':'9999999911','consent':False}).status_code==422