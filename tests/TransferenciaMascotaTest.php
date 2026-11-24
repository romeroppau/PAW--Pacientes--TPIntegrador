<?php

use PHPUnit\Framework\TestCase;

// MascotaRepositorio::transferir contra una base MySQL de PRUEBA (nunca contra Aiven): la del CI o
// el MySQL local del Docker Compose del Gateway (puerto 3307, base huellavet_test).
// Si la base no tiene las tablas, se crean con las migraciones reales (base/ del Gateway).
// Sin TEST_DB_PASSWORD o sin base, las pruebas se saltean.
final class TransferenciaMascotaTest extends TestCase
{
    private static ?PDO $db = null;
    private MascotaRepositorio $mascotas;
    private int $rescatista;
    private int $cliente;
    private int $otroCliente;

    public static function setUpBeforeClass(): void
    {
        $password = getenv('TEST_DB_PASSWORD');

        if ($password === false || $password === '') {
            return;
        }

        try {
            self::$db = new PDO(
                'mysql:host=127.0.0.1;port=3307;dbname=huellavet_test;charset=utf8mb4',
                'root',
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $error) {
            self::$db = null;
            return;
        }

        $base = (getenv('COMUN_DIR') ?: __DIR__ . '/../../PAW--ApiGateway--TPIntegrador/comun') . '/../base';
        require_once $base . '/funciones.php';

        foreach (['usuario' => '001_backend_usuario.sql', 'mascota' => '004_pacientes_mascota_turno.sql'] as $tabla => $archivo) {
            $existe = self::$db->query("SHOW TABLES LIKE '$tabla'")->fetchColumn();
            if ($existe === false) {
                ejecutarSql(self::$db, file_get_contents("$base/$archivo"));
            }
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('No hay base de prueba: levantá el MySQL local y cargá TEST_DB_PASSWORD');
        }

        self::limpiar();
        $this->rescatista = self::usuario('RESCATISTA');
        $this->cliente = self::usuario('CLIENTE');
        $this->otroCliente = self::usuario('CLIENTE');
        $this->mascotas = new MascotaRepositorio(self::$db);
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            self::limpiar();
        }
    }

    public function testPasaLaMascotaDelRescatistaAlAdoptante(): void
    {
        $id = self::mascota($this->rescatista);

        $this->assertSame('TRANSFERIDA', $this->mascotas->transferir($id, $this->rescatista, $this->cliente));
        $this->assertSame($this->cliente, self::duenio($id));
    }

    public function testRepetirLaTransferenciaNoCambiaNada(): void
    {
        $id = self::mascota($this->rescatista);
        $this->mascotas->transferir($id, $this->rescatista, $this->cliente);

        $this->assertSame('YA_TRANSFERIDA', $this->mascotas->transferir($id, $this->rescatista, $this->cliente));
        $this->assertSame($this->cliente, self::duenio($id));
    }

    public function testNoPasaUnaMascotaQueEsDeOtro(): void
    {
        $id = self::mascota($this->otroCliente);

        $this->assertSame('DUENIO_DISTINTO', $this->mascotas->transferir($id, $this->rescatista, $this->cliente));
        $this->assertSame($this->otroCliente, self::duenio($id));
    }

    public function testElNuevoDuenioTieneQueSerUnCliente(): void
    {
        $id = self::mascota($this->rescatista);
        $otroRescatista = self::usuario('RESCATISTA');

        $this->assertSame('NO_ES_CLIENTE', $this->mascotas->transferir($id, $this->rescatista, $otroRescatista));
        $this->assertSame('NO_ES_CLIENTE', $this->mascotas->transferir($id, $this->rescatista, 999999999));
        $this->assertSame($this->rescatista, self::duenio($id));
    }

    public function testUnaMascotaDadaDeBajaOInexistenteNoSePasa(): void
    {
        $id = self::mascota($this->rescatista);
        self::$db->exec("UPDATE mascota SET activo = 0 WHERE id = $id");

        $this->assertSame('NO_EXISTE', $this->mascotas->transferir($id, $this->rescatista, $this->cliente));
        $this->assertSame('NO_EXISTE', $this->mascotas->transferir(999999999, $this->rescatista, $this->cliente));
    }

    // ---------- Datos de prueba (emails @transferencia.test, para borrarlos sin tocar nada más) ----------

    private static function usuario(string $rol): int
    {
        $consulta = self::$db->prepare(
            "INSERT INTO usuario (nombre, email, password_hash, telefono, rol, estado_validacion)
             VALUES ('Prueba', ?, 'x', '2323555555', ?, ?)"
        );
        $consulta->execute([uniqid('', true) . '@transferencia.test', $rol, $rol === 'RESCATISTA' ? 'APROBADO' : null]);

        return (int) self::$db->lastInsertId();
    }

    private static function mascota(int $duenio): int
    {
        self::$db->prepare("INSERT INTO mascota (duenio_id, nombre, especie) VALUES (?, 'Pipa', 'GATO')")->execute([$duenio]);

        return (int) self::$db->lastInsertId();
    }

    private static function duenio(int $mascota): int
    {
        return (int) self::$db->query("SELECT duenio_id FROM mascota WHERE id = $mascota")->fetchColumn();
    }

    private static function limpiar(): void
    {
        self::$db->exec(
            "DELETE m FROM mascota m JOIN usuario u ON u.id = m.duenio_id WHERE u.email LIKE '%@transferencia.test'"
        );
        self::$db->exec("DELETE FROM usuario WHERE email LIKE '%@transferencia.test'");
    }
}
