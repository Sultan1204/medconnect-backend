<?php

namespace Tests\Unit;

use App\Modules\Identity\Models\AccessToken;
use App\Support\Database\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards cross-cutting invariants that a new model could silently break.
 */
class ArchitectureTest extends TestCase
{
    /** @return array<string, array{class-string<Model>}> */
    public static function models(): array
    {
        $models = [];

        foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/Models/*.php') as $file) {
            preg_match('#Modules/(\w+)/Models/(\w+)\.php$#', str_replace('\\', '/', $file), $m);
            $class = "App\\Modules\\{$m[1]}\\Models\\{$m[2]}";
            $models[$m[1].'/'.$m[2]] = [$class];
        }

        return $models;
    }

    /** @param class-string<Model> $class */
    #[DataProvider('models')]
    public function test_models_persist_dates_in_utc(string $class): void
    {
        $this->assertContains(StoresUtcDates::class, class_uses_recursive($class), "{$class} must use StoresUtcDates");
    }

    /** @param class-string<Model> $class */
    #[DataProvider('models')]
    public function test_models_are_registered_in_the_morph_map(string $class): void
    {
        if ($class === AccessToken::class) {
            $this->markTestSkipped('Sanctum token model is never a polymorphic target.');
        }

        $this->assertContains($class, config('morph_map'), "{$class} must be listed in config/morph_map.php");
    }

    public function test_module_route_files_are_registered(): void
    {
        $registered = config('modules.routes');

        foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/routes.php') as $file) {
            $relative = 'app/'.str_replace('\\', '/', substr($file, strpos(str_replace('\\', '/', $file), 'app/Modules') + 4));
            $this->assertContains($relative, $registered, "{$relative} is not listed in config/modules.php");
        }
    }
}
