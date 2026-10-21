<?php

use App\Models\User;

it('renders the app shell for a logged-in user', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/')->assertOk()->assertSee('IssueManager');
    $this->get('/c/1')->assertOk();
});

it('ships complete translations for every locale', function () {
    $loader = app('translator')->getLoader();
    $flat = function (array $a) use (&$flat) {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                foreach ($flat($v) as $kk => $vv) {
                    $out["$k.$kk"] = $vv;
                }
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    };
    $pt = array_keys($flat($loader->load('pt', 'ui')));
    $en = array_keys($flat($loader->load('en', 'ui')));
    expect(array_diff($pt, $en))->toBe([])->and(array_diff($en, $pt))->toBe([]);
});

it('redirects plain http to https (temporarily) when the app is served over https', function () {
    config(['app.force_https' => true]);
    $this->get('http://issuemanager.test/login?x=1')->assertStatus(302)->assertRedirect('https://issuemanager.test/login?x=1');
    $this->get('https://issuemanager.test/login')->assertOk();
});

it('serves the user\'s own issues as a page of the app (/issues) that boots on that page', function () {
    $this->actingAs(User::factory()->create(['email' => 'a@proside.pt']));

    $this->get('/issues')->assertOk()->assertSee('"page":"mine"', false);
    $this->get('/')->assertOk()->assertSee('"page":"chat"', false);
});

it('serves the existing issues as a page of the app (/issues/all)', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/issues/all')->assertOk()->assertSee('"page":"all"', false);
});
