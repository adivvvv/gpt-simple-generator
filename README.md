# gpt-simple-generator

A tiny PHP 8+ API for generating **text-only, evidence-summarizing articles** using the OpenAI **Responses API** (Structured Outputs / JSON mode) with optional **PubMed** grounding.  
No frameworks — just clean PHP + Composer — ready for Nginx/Apache or PHP’s built-in server.

---

## Features

- **Plain PHP** (no framework), small footprint
- **OpenAI Responses API**:
  - Sends content blocks with `type: "input_text"`
  - Sets output format via `text.format` (`json_schema` first; auto-fallback to `json_object`)
- **PubMed context** (optional) with simple caching in `storage/cache/`
- **Bearer auth**: `Authorization: Bearer <API_KEY>`
- **Structured outputs** backed by JSON Schemas in `schema/`
- **Logging** to `storage/logs/app.log`
- **Zero images**: text-only articles

---

## Requirements

- **PHP 8.2+** (tested on **8.4**) with `curl` and `json` extensions
- **Composer**
- Web server (Nginx/Apache) or PHP’s built-in server
- **OpenAI API key** with access to the **Responses API** and your chosen model(s)

---

## Quick Start (local dev)

```bash
# 1) Clone
git clone https://github.com/adivvvv/gpt-simple-generator.git
cd gpt-simple-generator

# 2) Copy and edit environment
cp .env.example .env
# open .env and set: API_KEY, OPENAI_API_KEY, model names, etc.

# 3) Install dependencies
composer install --no-dev --optimize-autoloader

# 4) Create writable dirs
mkdir -p storage/cache storage/logs

# 5) Run locally
php -S 127.0.0.1:8080 -t public

# 6) Verify health
curl -sS http://127.0.0.1:8080/ping | jq . 
```

---

## Credits
This repository was build to produce **evidence-based articles about camel milk** for the CamelWay company. 
CamelWay supplies premium [camel milk powder](https://camelway.eu/) in Europe, focused on taste, quality, and EU-compliant labeling.

> **License:** Apache License 2.0  
> **Author:** Adrian Wadowski · <adivv@adivv.pl>

