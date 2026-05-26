# Pull Request Template

## Description

Please include a summary of the changes and the related issue. List any dependencies that are required for this change.

Fixes # (issue)

## Type of change

- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update
- [ ] Code style/refactoring

## How Has This Been Tested?

Please describe the tests that you ran to verify your changes. Provide instructions so we can reproduce. Please also list any relevant details for your test configuration.

- [ ] Manual test in WordPress Admin
- [ ] Unit tests (if applicable)
- [ ] PHP Linting passed

## AI Usage

- [ ] I used AI (LLM) to assist in generating parts of this code.
- [ ] I have reviewed and tested all AI-generated portions.

## Checklist

- [ ] My code follows the WordPress coding standards.
- [ ] I have performed a self-review of my own code.
- [ ] I have commented my code, particularly in hard-to-understand areas.
- [ ] I have made corresponding changes to the documentation.
- [ ] My changes generate no new warnings.
- [ ] I have added tests that prove my fix is effective or that my feature works.
- [ ] New and existing unit tests pass locally with my changes.

## Security Checklist

- [ ] User input is sanitized on input and escaped on output (`sanitize_*`, `esc_*`).
- [ ] All custom DB queries use `$wpdb->prepare()` (or equivalent placeholders).
- [ ] Admin-post / AJAX / REST endpoints check capabilities AND nonces (`current_user_can` + `wp_verify_nonce` / `permission_callback`).
- [ ] No secrets, API tokens, or production identifiers (order IDs, emails, customer names) are committed.
- [ ] File uploads validate MIME type, extension, and size; uploads land outside the webroot or in WP's managed uploads dir.
- [ ] External HTTP calls use `wp_remote_*` with timeouts and verify SSL.
- [ ] New third-party dependencies are reviewed (license, maintenance, CVE history) and pinned to exact versions.
- [ ] No PII is written to logs at INFO/DEBUG level without redaction.
