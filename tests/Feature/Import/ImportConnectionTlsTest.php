<?php

namespace Tests\Feature\Import;

use App\Import\Fossbilling\FossbillingImporter;
use App\Import\Whmcs\WhmcsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Pdo\Mysql;
use Tests\TestCase;

/**
 * The source database is read over TLS when it is on another server. connect() only sets up the
 * connection, so none of this opens a socket.
 */
class ImportConnectionTlsTest extends TestCase
{
    use RefreshDatabase;

    private string $caFile;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The pdo_mysql extension is not loaded.');
        }

        $this->caFile = tempnam(sys_get_temp_dir(), 'ca');
        file_put_contents($this->caFile, "-----BEGIN CERTIFICATE-----\n-----END CERTIFICATE-----\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->caFile);
        putenv('SSL_CERT_FILE');

        parent::tearDown();
    }

    public function test_a_database_on_another_server_is_read_over_tls_with_its_certificate_checked(): void
    {
        FossbillingImporter::connect(['host' => 'db.example.test', 'database' => 'fossbilling', 'username' => 'reader', 'ssl_ca' => $this->caFile]);

        $options = $this->pdoOptions(FossbillingImporter::CONNECTION);
        $this->assertTrue($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        $this->assertSame($this->caFile, $options[Mysql::ATTR_SSL_CA]);
    }

    public function test_without_a_ca_file_this_servers_own_list_is_used(): void
    {
        putenv('SSL_CERT_FILE='.$this->caFile);

        WhmcsImporter::connect(['host' => '203.0.113.9', 'database' => 'whmcs', 'username' => 'reader']);

        $options = $this->pdoOptions(WhmcsImporter::CONNECTION);
        $this->assertTrue($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        $this->assertTrue(filled($options[Mysql::ATTR_SSL_CA] ?? $options[Mysql::ATTR_SSL_CAPATH] ?? null));
    }

    public function test_the_same_server_and_a_server_without_tls_are_not_encrypted(): void
    {
        foreach ([['host' => 'localhost'], ['host' => '127.0.0.1'], ['host' => 'db.example.test', 'tls' => false]] as $credentials) {
            WhmcsImporter::connect($credentials + ['database' => 'whmcs', 'username' => 'reader']);

            $options = $this->pdoOptions(WhmcsImporter::CONNECTION);
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_CA, $options, $credentials['host']);
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_VERIFY_SERVER_CERT, $options, $credentials['host']);
        }
    }

    public function test_a_missing_ca_file_stops_before_connecting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WhmcsImporter::connect(['host' => 'db.example.test', 'database' => 'whmcs', 'username' => 'reader', 'ssl_ca' => $this->caFile.'-missing']);
    }

    public function test_staff_see_why_the_connection_was_not_saved(): void
    {
        $this->signInAdmin();

        $this->put(route('admin.settings.import.update'), [
            'source' => 'whmcs',
            'host' => 'db.example.test',
            'port' => 3306,
            'database' => 'whmcs',
            'username' => 'reader',
            'password' => 'secret',
            'tls' => '1',
            'ssl_ca' => $this->caFile.'-missing',
        ])->assertSessionHas('error');

        $this->assertSame([], (array) setting('import.connection'), 'Nothing was saved');
        $this->get(route('admin.settings.import.index'))->assertOk()->assertSee('Use an encrypted connection (TLS)');
    }

    /**
     * @return array<int, mixed>
     */
    private function pdoOptions(string $connection): array
    {
        return (array) config('database.connections.'.$connection.'.options');
    }
}
