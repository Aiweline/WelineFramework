# Data Model: AST Template Compiler

## Tokens
- **Token**
  - type: TEXT | TAG_OPEN | TAG_CLOSE | TAG_SELF_CLOSE | ATTR_NAME | ATTR_VALUE | PHP_BLOCK | EXPR | EOF
  - value: raw lexeme
  - line: line number
  - column: column number

## AST Nodes
- **Node**
  - line: line number

- **ProgramNode** (root)
  - children: Node[]

- **FragmentNode**
  - children: Node[]

- **TextNode**
  - value: string

- **PhpNode**
  - code: string (raw PHP)

- **TagNode**
  - name: string
  - attributes: AttrNode[]
  - children: Node[]
  - selfClosing: bool
  - executionStage: COMPILE_TIME | RUNTIME
  - handler: callable reference

- **AttrNode**
  - name: string
  - value: Node | Node[]

## Tag Definition Metadata
- name: string
- executionStage: COMPILE_TIME | RUNTIME
- handler: callable
- rules: runtime-only when arguments are dynamic

## Compile-Time Context
- taglib: current Taglib instance
- templateContext: template variables
- helpers: allowed helper functions/services

## Cache Metadata
- Uses existing template cache strategy; no new fields introduced.
