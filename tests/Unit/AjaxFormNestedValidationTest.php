<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AjaxFormNestedValidationTest extends TestCase
{
    public function test_ajax_form_maps_laravel_dot_errors_to_bracketed_input_names(): void
    {
        $script = file_get_contents(__DIR__.'/../../public/js/app.js');

        $this->assertStringContainsString("field.replace(/\\.([^.]+)/g, '[$1]')", $script);
        $this->assertStringContainsString('js-generated-error', $script);
    }
}
