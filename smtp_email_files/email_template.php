<?php

function smtp_render_email_template(string $title, string $content): string
{
    $templatePath = dirname(__DIR__) . '/emails/templates/base.html';

    if (is_file($templatePath)) {
        $template = file_get_contents($templatePath);
    } else {
        $template = '<!doctype html><html><head><meta charset="UTF-8"><title>{{title}}</title></head><body>{{content}}</body></html>';
    }

    return str_replace(
        ['{{title}}', '{{content}}', '{{year}}'],
        [$title, $content, date('Y')],
        $template
    );
}
