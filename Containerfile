# TODO (aluno): complete conforme a stack escolhida.
# Requisitos do contrato (contrato.json, secao exigencias_tecnicas):
#   - a API deve escutar na porta 8080 DENTRO do container;
#   - nenhuma variavel de ambiente obrigatoria;
#   - o build seguido de `docker run -p <PORTA_API>:8080 <imagem>`
#     deve bastar para a suíte.
#
# Exemplos de base (apague o que nao usar):
#
# ---- Python/FastAPI ----
# FROM python:3.12-slim
# WORKDIR /app
# COPY requirements.txt .
# RUN pip install --no-cache-dir -r requirements.txt
# COPY src/ ./src/
# EXPOSE 8080
# CMD ["uvicorn", "src.main:app", "--host", "0.0.0.0", "--port", "8080"]
#
# ---- Node/Express ----
# FROM node:20-alpine
# WORKDIR /app
# COPY package*.json ./
# RUN npm ci --omit=dev
# COPY src/ ./src/
# EXPOSE 8080
# CMD ["node", "src/index.js"]
#
# ---- Java/Spring ----
# (multi-stage: maven build + jre run — veja o track 02 para inspiracao)

# FROM scratch

FROM php:8.3-cli-alpine

WORKDIR /app
COPY src/ /app/src/
COPY variante/ /app/variante/

RUN mkdir -p /data && chmod 777 /data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/src", "/app/src/index.php"]
