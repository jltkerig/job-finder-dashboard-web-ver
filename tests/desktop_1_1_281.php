<?php
// Tests for what the web version took over from the desktop Job Finder v1.1.274 - v1.1.281 on the Companies page:
// Saved Companies and the page's remembered state. Included by run.php (it uses its check() and its throwaway
// database). Every company here is made up.

declare(strict_types=1);

require_once APP_ROOT . '/includes/companies_saved.php';

set_setting_json('saved_companies', []);
[$first, $added] = saved_company_add(['name' => 'Sample Studio, Inc.', 'website' => 'sample.example.com', 'address' => '1 Main St, Harrisburg, PA 17101', 'place' => 'Harrisburg, PA'], 'MD');
check('saved: a result is kept with what the search found', $added && $first['name'] === 'Sample Studio, Inc.' && $first['website'] === 'https://sample.example.com');
[$again, $added_again] = saved_company_add(['name' => 'Sample Studio'], 'MD');
check('saved: the same company is not kept twice', !$added_again && $again['id'] === $first['id'] && count(saved_companies_load()) === 1);
check('saved: listed without a center, then deleted',
    count(saved_companies_everything()) === 1 && saved_company_delete($first['id']) && !saved_company_delete($first['id']) && saved_companies_load() === []);

set_setting_json('companies_page', []);
companies_page_state_save(['results' => ['place' => 'Harrisburg, PA', 'shown' => 'Harrisburg, PA', 'miles' => '15', 'companies' => [['id' => 'node/1', 'name' => 'Sample']]]]);
companies_page_state_save(['hidden' => ['sample|1 main st']]);  // a second save keeps the first
$state = companies_page_state();
check('page state: results and hidden list are both kept', ($state['results']['companies'][0]['name'] ?? '') === 'Sample' && $state['hidden'] === ['sample|1 main st']);
companies_page_state_save(['results' => 'nope', 'hidden' => 'nope']);
check('page state: junk is ignored', companies_page_state() === $state);
