<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dutch_is_the_default_language(): void
    {
        $this->get('/login')->assertOk()->assertSee('Inloggen')->assertSee('<html lang="nl">', false);
    }

    public function test_english_can_be_selected_via_cookie(): void
    {
        $this->withUnencryptedCookie('locale', 'en')->get('/login')->assertOk()->assertSee('Sign in')->assertSee('<html lang="en">', false);
    }

    public function test_unknown_locale_falls_back_to_default(): void
    {
        $this->withUnencryptedCookie('locale', 'xx')->get('/login')->assertSee('Inloggen');
    }

    public function test_login_error_is_translated(): void
    {
        $this->from('/login')->post('/login', ['email' => 'a@b.nl', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'Deze inloggegevens komen niet overeen met onze gegevens.']);
    }
}
