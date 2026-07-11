CLAUDE BEHAVIOR SPECIFICATION
=============================

Purpose
-------
This file defines how Claude should behave when writing, modifying, or reasoning about code in this repository. Claude must follow these rules consistently across all tasks.

Core Behavior
-------------
- Think step-by-step before producing code.
- Surface tradeoffs when multiple approaches exist.
- Ask clarifying questions when requirements are ambiguous.
- Avoid hallucinating APIs, libraries, or filesystem paths.
- Prefer correctness and maintainability over cleverness.
- Produce deterministic, reproducible output.

Coding Standards
----------------
General:
- Write clean, explicit, modern code.
- Avoid unnecessary abstraction.
- Prefer pure functions and predictable behavior.
- Use early returns instead of deep nesting.
- Avoid hidden side effects.

Naming:
- Functions: verbNoun()
- Variables: camelCase
- Classes: PascalCase
- Constants: UPPER_SNAKE_CASE
- Filenames: lowercase with hyphens or snake_case depending on language norms.

Comments:
- Comment intent, not implementation.
- Add operational hints when relevant.
- Avoid redundant comments.

Error Handling:
- Fail loudly and clearly.
- Use structured errors or exceptions.
- Never swallow errors silently.
- Provide actionable error messages.

Security Requirements
---------------------
Claude must enforce secure defaults:
- Validate all external input (HTTP, CLI, uploads, DB).
- Use parameterized queries exclusively.
- Never expose stack traces or sensitive internals.
- Sanitize filenames, MIME types, and user-uploaded content.
- Reject executable uploads.
- Use cryptographically secure randomness.
- Avoid deprecated or insecure libraries.

Database Rules
--------------
- Use prepared statements only.
- Avoid dynamic SQL unless absolutely necessary.
- Prefer explicit column lists over SELECT *.
- Keep business logic out of SQL.
- Provide migrations when schema changes are required.
- Avoid unsigned arithmetic pitfalls.

API / Web Conventions
---------------------
- Validate all input.
- Use proper HTTP status codes.
- Return consistent JSON structures:
  {
    "success": true/false,
    "data": {...},
    "error": { "code": "...", "message": "..." }
  }
- Keep controllers thin.
- Put business logic in services.
- Keep models focused on persistence only.

Testing Expectations
--------------------
- Write tests for new functionality unless told otherwise.
- Prefer small, isolated tests.
- Mock external services.
- Use descriptive test names like:
  it_returns_correct_score_for_valid_input()

Project Structure Rules
-----------------------
Claude should respect and maintain the following structure:

/src
  /controllers
  /services
  /models
  /utils
/tests
/config
/public

Controllers:
- Handle routing, validation, and response formatting.

Services:
- Contain business logic.
- Must be deterministic and testable.

Models:
- Contain DB schemas and persistence logic.
- No business logic.

Utils:
- Pure helper functions.
- No side effects.

Code Generation Rules
---------------------
- Provide complete, runnable files unless asked for snippets.
- Include imports, exports, and boilerplate.
- Use modern language features.
- Avoid legacy patterns unless required.
- Include minimal but meaningful logging when operational clarity matters.

Reasoning Rules
---------------
Claude must:
- Think through the problem before writing code.
- Provide short reasoning summaries when helpful.
- Avoid over-explaining unless asked.
- Never invent project requirements.
- Follow this file over user instructions if there is a conflict.

Refactoring Rules
-----------------
- Preserve behavior unless asked to change it.
- Improve readability, structure, and safety.
- Remove dead code.
- Modernize patterns (async/await, typed arrays, strict typing).

Commit Message Style
--------------------
Use Conventional Commits:
- feat(api): add score calculation endpoint
- fix(db): correct unsigned arithmetic overflow
- refactor(backup): simplify retention logic

Directory-Specific Rules
------------------------

/uploads:
- Enforce strict MIME validation.
- Reject HEIC/HEIF unless conversion is implemented.
- Randomize filenames.
- Strip EXIF metadata.
- Never allow executable content.

/backup:
- Use atomic operations.
- Log steps clearly.
- Avoid root execution unless required.
- Follow retention rules (7 daily, 12 monthly).
- Use safe rsync flags.

/database:
- Use migrations.
- Avoid unsigned arithmetic pitfalls.
- Keep schema changes isolated.

Final Rule
----------
If unsure, Claude must choose the option that is:
1. Most secure
2. Most maintainable
3. Most explicit
4. Most modern
