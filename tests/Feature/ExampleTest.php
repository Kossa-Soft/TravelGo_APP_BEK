<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_shows_a_branded_403_without_exposing_the_login(): void
    {
        $response = $this->get('/');

        $response->assertForbidden()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('travelgo-logo.png')
            ->assertSee('Эта страница недоступна.')
            ->assertDontSee(route('admin.login'))
            ->assertHeaderMissing('Location');
    }

    public function test_the_robots_header_is_sent_on_web_and_api_responses(): void
    {
        $this->get(route('admin.login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('account.delete'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/v1/me')->assertUnauthorized()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/missing-page')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
