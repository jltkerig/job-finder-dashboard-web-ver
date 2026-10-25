<?php
// Ready-made rules to tick on the Resume Rules and Cover Letter Rules boxes, and the greeting suggestions in the cover
// letter editor. Ticking a rule adds its line to the rules box (static/js/rule-presets.js); the box is still what gets
// saved, so nothing here is stored. Ported from the desktop Resume Builder's rule_presets.py.

declare(strict_types=1);

const RULE_PRESETS = [
    'cover_letter' => [
        ['heading' => 'Greeting', 'rules' => [
            ['id' => 'cl-greet-no-team', 'text' => 'Never open with "Dear Hiring Team" or "Dear Hiring Manager"; they read as obvious AI.'],
            ['id' => 'cl-greet-name', 'text' => 'Address the hiring manager by name when the posting gives one; otherwise open with "Hello,".'],
        ]],
        ['heading' => 'Opening', 'rules' => [
            ['id' => 'cl-open-no-writing', 'text' => 'Do not start with "I am writing to apply" or "I am excited to apply".'],
            ['id' => 'cl-open-specific', 'text' => 'Open with one specific reason this role stood out, from the posting.'],
        ]],
        ['heading' => 'Tone', 'rules' => [
            ['id' => 'cl-tone-lowkey', 'text' => 'Keep the tone low-key and professional, never gushing or sales-heavy.'],
            ['id' => 'cl-tone-human', 'text' => 'Write the way a person would, in plain language with normal variation in sentence length.'],
            ['id' => 'cl-tone-no-oversell', 'text' => 'Do not call me "the perfect candidate", "uniquely qualified" or "thrilled".'],
        ]],
        ['heading' => 'Wording to avoid', 'rules' => [
            ['id' => 'cl-avoid-buzz', 'text' => 'Avoid buzzwords such as passionate, leverage, synergy, dynamic, results-driven and cutting-edge.'],
            ['id' => 'cl-avoid-dash', 'text' => 'Do not use em dashes.'],
            ['id' => 'cl-avoid-triplets', 'text' => 'Avoid lists of three adjectives and repeated sentence patterns.'],
        ]],
        ['heading' => 'Length and format', 'rules' => [
            ['id' => 'cl-len-short', 'text' => 'Keep the letter to one page, three or four short paragraphs.'],
            ['id' => 'cl-len-real', 'text' => 'Use only experience from my resume; never invent tools, metrics or responsibilities.'],
        ]],
        ['heading' => 'Closing', 'rules' => [
            ['id' => 'cl-close-plain', 'text' => 'End with a plain closing line and "Sincerely,"; no "I look forward to hearing from you at your earliest convenience".'],
        ]],
    ],
    'resume' => [
        ['heading' => 'Sections', 'rules' => [
            ['id' => 'rs-sec-skills', 'text' => 'Use a Technical Skills section instead of a Professional Summary.'],
            ['id' => 'rs-sec-order', 'text' => 'Put the sections that best match the posting first.'],
        ]],
        ['heading' => 'Honesty', 'rules' => [
            ['id' => 'rs-hon-metrics', 'text' => 'Never invent metrics, tools, certifications or responsibilities.'],
            ['id' => 'rs-hon-posting', 'text' => 'Do not add requirements just because the posting lists them.'],
        ]],
        ['heading' => 'Bullets', 'rules' => [
            ['id' => 'rs-bul-verb', 'text' => 'Start each bullet with a strong action verb.'],
            ['id' => 'rs-bul-person', 'text' => 'Do not use "I" or "my" in bullets.'],
            ['id' => 'rs-bul-short', 'text' => 'Keep bullets to one or two lines.'],
        ]],
        ['heading' => 'Wording to avoid', 'rules' => [
            ['id' => 'rs-avoid-buzz', 'text' => 'Avoid buzzwords such as passionate, synergy, dynamic and results-driven.'],
            ['id' => 'rs-avoid-dash', 'text' => 'Do not use em dashes.'],
        ]],
        ['heading' => 'Length and format', 'rules' => [
            ['id' => 'rs-len-page', 'text' => 'Keep the resume to one page when the experience allows.'],
            ['id' => 'rs-len-ats', 'text' => 'Keep it easy for applicant tracking systems to read: plain headings, selectable text.'],
        ]],
    ],
];

/** The first recipient line when it looks like a person's name ("Jane Smith"), else ''. */
function rule_person_name(array $recipient): string
{
    $company_words = '/\b(inc|llc|ltd|corp|company|co|group|labs?|team|hr|human resources|recruit\w*|talent|department|dept|office|university|school|systems|solutions|technologies)\b/i';
    foreach ($recipient as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $words = preg_split('/\s+/', $line);
        $looks_like_person = count($words) >= 2 && count($words) <= 4 && count(array_filter($words, fn($w) => preg_match("/^[A-Za-z][A-Za-z.'-]*,?$/", $w))) === count($words);
        return $looks_like_person && !preg_match($company_words, $line) ? $line : '';
    }
    return '';
}

/** Greetings to offer in the editor: names and the company are filled in when known. The last option (added by the page) is no greeting. */
function greeting_options(array $recipient, string $company = ''): array
{
    $name = rule_person_name($recipient);
    $company = trim($company);
    $first = $name !== '' ? explode(' ', $name)[0] : '';
    $options = [$name !== '' ? "Hello $name," : 'Hello,', $name !== '' ? "Dear $name," : '', $first !== '' ? "Hi $first," : '',
        'Hello,', 'Good morning,', $company !== '' ? "To the team at $company," : ''];
    return array_values(array_unique(array_filter($options, fn($o) => $o !== '')));
}

/** The "Add common rules" dropdown above a rules box: $kind is resume or cover_letter, $target the box's id. */
function rule_menu(string $kind, string $target): string
{
    $html = '<details class="rule-presets" data-target="' . h($target) . '"><summary class="bordered-button">Add common rules</summary><div class="rule-presets-list">';
    foreach (RULE_PRESETS[$kind] as $group) {
        $html .= '<fieldset><legend>' . h($group['heading']) . '</legend>';
        foreach ($group['rules'] as $rule) {
            $html .= '<label><input type="checkbox" data-heading="' . h($group['heading']) . '" data-rule="' . h($rule['text']) . '" /> ' . h($rule['text']) . '</label>';
        }
        $html .= '</fieldset>';
    }
    return $html . '</div></details>';
}
