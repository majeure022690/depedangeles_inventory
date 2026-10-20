<?php

namespace Tests\Feature;

use App\Models\Equipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Printed QR labels encode APP_URL/equipment/{id}/edit (ADR §8). The
 * backend's only web route keeps them working by redirecting to the SPA.
 */
class EquipmentQrRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_printed_label_url_redirects_to_the_spa_edit_page(): void
    {
        config(['app.frontend_url' => 'https://dictis.example.gov.ph/']);
        $equipment = Equipment::factory()->create();

        $this->assertSame(url("/equipment/{$equipment->id}/edit"), $equipment->fresh()->qr_code);

        $this->get("/equipment/{$equipment->id}/edit")
            ->assertRedirect("https://dictis.example.gov.ph/equipment/{$equipment->id}/edit");
    }

    public function test_the_redirect_target_never_comes_from_the_request(): void
    {
        config(['app.frontend_url' => 'http://localhost:5173']);
        $equipment = Equipment::factory()->create();

        $this->get("/equipment/{$equipment->id}/edit?redirect=https://evil.example", ['Host' => 'evil.example'])
            ->assertRedirect("http://localhost:5173/equipment/{$equipment->id}/edit");
    }

    public function test_an_unknown_id_gets_the_same_redirect_so_existence_is_not_revealed(): void
    {
        config(['app.frontend_url' => 'http://localhost:5173']);

        $this->get('/equipment/999999/edit')
            ->assertStatus(302)
            ->assertRedirect('http://localhost:5173/equipment/999999/edit');
    }

    public function test_a_non_numeric_id_is_a_404(): void
    {
        $this->get('/equipment/not-a-number/edit')->assertNotFound();
        $this->get('/equipment/1%2F..%2Fevil/edit')->assertNotFound();
    }

    public function test_no_other_web_routes_exist(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/dashboard')->assertNotFound();
        $this->get('/equipment')->assertNotFound();
    }
}
