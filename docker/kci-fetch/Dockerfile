# Debian (glibc) base: curl_cffi ships manylinux wheels, Alpine/musl is not supported.
FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY kci_fetch.py .

ENV PYTHONUNBUFFERED=1
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=3)"
USER nobody
CMD ["python", "kci_fetch.py"]
