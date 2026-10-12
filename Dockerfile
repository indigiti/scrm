FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1 SCHOOLFLOW_DB=/data/schoolflow.sqlite3
WORKDIR /app
COPY requirements.txt /app/
RUN pip install --no-cache-dir -r requirements.txt && useradd --create-home --uid 10001 schoolflow && mkdir -p /data && chown -R schoolflow:schoolflow /data
COPY app.py /app/
COPY static/ /app/static/
USER schoolflow
EXPOSE 8000
CMD ["uvicorn","app:app","--host","0.0.0.0","--port","8000"]