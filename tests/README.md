# PHPUnit

Run from the plugin root (requires [Composer](https://getcomposer.org/)):

```bash
composer install
composer test
```

Tests use minimal WordPress stubs in `tests/stubs-wordpress.php` so they run without a full WordPress bootstrap. They cover `vibe_check_sanitize_quiz_payload`, `vibe_check_merge_parsed_results_cta_from_base`, and `vibe_check_rest_existing_within_limit` in [`includes/class-vibe-check-quiz-data.php`](../includes/class-vibe-check-quiz-data.php).
