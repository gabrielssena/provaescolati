# Fila de Atendimento

API REST de senhas para atendimento presencial, em PHP 8.3 com SQLite.
Prefixo das senhas: `F`. Razão preferencial: 3 preferenciais para 1 normal.

## Requisitos

- Docker (para rodar em container), ou
- PHP 8.3+ com a extensão `pdo_sqlite` (para rodar local)

## Subir em container

```bash
docker build -f Containerfile -t fila .
docker run --rm -p 9202:8080 -v "$(pwd)/dados:/data" fila
```

A API fica em `http://localhost:9202`. Dentro do container ela escuta na porta 8080.

## Subir localmente

```bash
php -S 0.0.0.0:8080 -t src src/index.php
```

A API fica em `http://localhost:8080`.

## Persistência

Os dados ficam em `/data/fila.sqlite`. Monte um volume em `/data` para que fila,
sequência e painel sobrevivam à recriação do container. Se `/data` não existir ou
não for gravável, a API usa o diretório temporário do sistema (sem persistência real).

## Endpoints

| Método | Rota | Descrição |
| --- | --- | --- |
| GET | /healthz | Verificação de saúde |
| POST | /senhas | Emite senha (`{"tipo": "normal"\|"preferencial"}`) |
| GET | /senhas/proxima | Chama a próxima senha |
| POST | /senhas/{codigo}/concluir | Conclui uma senha chamada |
| POST | /senhas/{codigo}/rechamar | Rechama uma senha chamada |
| POST | /senhas/{codigo}/cancelar | Cancela uma senha que aguarda |
| GET | /painel | Últimas 5 senhas chamadas |