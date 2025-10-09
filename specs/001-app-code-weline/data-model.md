# Data Model: Weline_Ai (draft)

## Entities

### ai_model
- id: PK
- supplier: string
- name: string
- model_code: string
- version: string
- config: json
- max_tokens: integer
- input_cost: decimal
- output_cost: decimal
- capabilities: json
- proxy_info: json
- tags: json
- status: enum(active,inactive,deprecated)
- is_active: boolean
- is_copy: boolean DEFAULT 0
- origin_model_id: integer NULLABLE (FK -> ai_model.id)
- created_time: datetime
- updated_time: datetime

Indexes:
- model_code (unique)
- origin_model_id

### ai_assistant
- id: PK
- name: string
- prompt: text
- model_code: string
- model_config: json
- mcp_config: json
- user_proxy: json
- user_id: integer
- is_active: boolean
- created_time
- updated_time

### ai_api_key
- id: PK
- name: string
- user_id: integer
- token: string (encrypted storage)
- status: enum(pending,approved,rejected,frozen,deleted)
- quota_limit: integer
- used_quota: integer
- is_active: boolean
- created_time
- updated_time

## Notes
- Ensure `origin_model_id` references are maintained when models are deleted/archived. Original scanned models should have `is_copy = 0` and be protected by business rules.


