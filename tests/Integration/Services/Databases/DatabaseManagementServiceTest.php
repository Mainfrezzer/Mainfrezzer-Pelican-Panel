<?php

namespace App\Tests\Integration\Services\Databases;

use App\Models\Database;
use App\Models\DatabaseHost;
use App\Tests\Integration\IntegrationTestCase;
use App\Services\Databases\DatabaseManagementService;
use App\Exceptions\Repository\DuplicateDatabaseNameException;
use App\Exceptions\Service\Database\TooManyDatabasesException;
use App\Exceptions\Service\Database\DatabaseClientFeatureNotEnabledException;

class DatabaseManagementServiceTest extends IntegrationTestCase
{
    /**
     * Setup tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('panel.client_features.databases.enabled', true);
    }

    /**
     * Test that the name generated by the unique name function is what we expect.
     */
    public function testUniqueDatabaseNameIsGeneratedCorrectly(): void
    {
        $this->assertSame('s1_example', DatabaseManagementService::generateUniqueDatabaseName('example', 1));
        $this->assertSame('s123_something_else', DatabaseManagementService::generateUniqueDatabaseName('something_else', 123));
        $this->assertSame('s123_' . str_repeat('a', 43), DatabaseManagementService::generateUniqueDatabaseName(str_repeat('a', 100), 123));
    }

    /**
     * Test that disabling the client database feature flag prevents the creation of databases.
     */
    public function testExceptionIsThrownIfClientDatabasesAreNotEnabled(): void
    {
        config()->set('panel.client_features.databases.enabled', false);

        $this->expectException(DatabaseClientFeatureNotEnabledException::class);

        $server = $this->createServerModel();
        $this->getService()->create($server, []);
    }

    /**
     * Test that a server at its database limit cannot have an additional one created if
     * the $validateDatabaseLimit flag is not set to false.
     */
    public function testDatabaseCannotBeCreatedIfServerHasReachedLimit(): void
    {
        $server = $this->createServerModel(['database_limit' => 2]);
        $host = DatabaseHost::factory()->recycle($server->node)->create();

        Database::factory()->times(2)->create(['server_id' => $server->id, 'database_host_id' => $host->id]);

        $this->expectException(TooManyDatabasesException::class);

        $this->getService()->create($server, []);
    }

    /**
     * Test that a missing or invalid database name format causes an exception to be thrown.
     *
     * @dataProvider invalidDataDataProvider
     */
    public function testEmptyDatabaseNameOrInvalidNameTriggersAnException(array $data): void
    {
        $server = $this->createServerModel();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The database name passed to DatabaseManagementService::handle MUST be prefixed with "s{server_id}_".');

        $this->getService()->create($server, $data);
    }

    /**
     * Test that creating a server database with an identical name triggers an exception.
     */
    public function testCreatingDatabaseWithIdenticalNameTriggersAnException(): void
    {
        $server = $this->createServerModel();
        $name = DatabaseManagementService::generateUniqueDatabaseName('something', $server->id);

        $host = DatabaseHost::factory()->recycle($server->node)->create();
        $host2 = DatabaseHost::factory()->recycle($server->node)->create();
        Database::factory()->create([
            'database' => $name,
            'database_host_id' => $host->id,
            'server_id' => $server->id,
        ]);

        $this->expectException(DuplicateDatabaseNameException::class);
        $this->expectExceptionMessage('A database with that name already exists for this server.');

        // Try to create a database with the same name as a database on a different host. We expect
        // this to fail since we don't account for the specific host when checking uniqueness.
        $this->getService()->create($server, [
            'database' => $name,
            'database_host_id' => $host2->id,
        ]);

        $this->assertDatabaseMissing('databases', ['server_id' => $server->id]);
    }

    /**
     * Test that a server database can be created successfully.
     */
    public function testServerDatabaseCanBeCreated(): void
    {
        $this->markTestSkipped();
        /* TODO: The exception is because the transaction is closed
            because the database create closes it early */

        $server = $this->createServerModel();
        $name = DatabaseManagementService::generateUniqueDatabaseName('something', $server->id);

        $host = DatabaseHost::factory()->recycle($server->node)->create();

        $username = null;
        $secondUsername = null;
        $password = null;

        $response = $this->getService()->create($server, [
            'remote' => '%',
            'database' => $name,
            'database_host_id' => $host->id,
        ]);

        $this->assertInstanceOf(Database::class, $response);
        $this->assertSame($response->server_id, $server->id);
        $this->assertMatchesRegularExpression('/^(u\d+_)(\w){10}$/', $username);
        $this->assertSame($username, $secondUsername);
        $this->assertSame(24, strlen($password));

        $this->assertDatabaseHas('databases', ['server_id' => $server->id, 'id' => $response->id]);
    }

    /**
     * Test that an exception encountered while creating the database leads to the cleanup code
     * being called and any exceptions encountered while cleaning up go unreported.
     */
    public function testExceptionEncounteredWhileCreatingDatabaseAttemptsToCleanup(): void
    {
        $this->markTestSkipped();

        /* TODO: I think this is useful logic to be tested,
            but this is a very hacky way of going about it.
            The exception is because the transaction is closed
            because the database create closes it early */

        $server = $this->createServerModel();
        $name = DatabaseManagementService::generateUniqueDatabaseName('something', $server->id);

        $host = DatabaseHost::factory()->recycle($server->node)->create();

        $this->repository->expects('createDatabase')->with($name)->andThrows(new \BadMethodCallException());
        $this->repository->expects('dropDatabase')->with($name);
        $this->repository->expects('dropUser')->withAnyArgs()->andThrows(new \InvalidArgumentException());

        $this->expectException(\BadMethodCallException::class);

        $this->getService()->create($server, [
            'remote' => '%',
            'database' => $name,
            'database_host_id' => $host->id,
        ]);

        $this->assertDatabaseMissing('databases', ['server_id' => $server->id]);
    }

    public static function invalidDataDataProvider(): array
    {
        return [
            [[]],
            [['database' => '']],
            [['database' => 'something']],
            [['database' => 's_something']],
            [['database' => 's12s_something']],
            [['database' => 's12something']],
        ];
    }

    private function getService(): DatabaseManagementService
    {
        return $this->app->make(DatabaseManagementService::class);
    }
}
