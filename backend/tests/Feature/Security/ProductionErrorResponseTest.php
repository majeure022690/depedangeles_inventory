<?php

namespace Tests\Feature\Security;

use App\Models\Equipment;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ProductionErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
        config(['app.debug' => false]);
    }

    /**
     * @return array{int, mixed}
     */
    private function render(Throwable $e): array
    {
        $request = Request::create('/api/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->app->make(ExceptionHandler::class)->render($request, $e);

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    }

    public function test_validation_errors_keep_their_422_and_field_messages(): void
    {
        [$status, $body] = $this->render(ValidationException::withMessages(['email' => 'Invalid credentials.']));

        $this->assertSame(422, $status);
        $this->assertSame(['Invalid credentials.'], $body['errors']['email']);
    }

    public function test_unauthenticated_requests_keep_their_401(): void
    {
        [$status] = $this->render(new AuthenticationException);

        $this->assertSame(401, $status);
    }

    public function test_csrf_mismatch_keeps_its_419(): void
    {
        [$status] = $this->render(new TokenMismatchException);

        $this->assertSame(419, $status);
    }

    public function test_missing_records_do_not_reveal_model_names(): void
    {
        [$status, $body] = $this->render((new ModelNotFoundException)->setModel(Equipment::class, [1]));

        $this->assertSame(404, $status);
        $this->assertSame(['message' => 'Not Found.'], $body);
    }

    public function test_unexpected_errors_stay_generic(): void
    {
        [$status, $body] = $this->render(new RuntimeException('secret detail'));

        $this->assertSame(500, $status);
        $this->assertSame(['message' => 'An unexpected error occurred.'], $body);
    }
}
