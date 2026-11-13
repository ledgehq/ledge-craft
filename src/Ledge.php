<?php

declare(strict_types=1);

namespace ledgehq\craftledge;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use ledgehq\craftledge\console\controllers\DefaultController;
use ledgehq\craftledge\models\Settings;
use ledgehq\craftledge\services\AcquireService;
use ledgehq\craftledge\services\ContentService;
use ledgehq\craftledge\services\DependenciesService;
use ledgehq\craftledge\services\HealthService;
use yii\base\Event;

/**
 * @method static Ledge getInstance()
 * @method Settings getSettings()
 * @property-read HealthService $health
 * @property-read AcquireService $acquire
 * @property-read DependenciesService $dependencies
 * @property-read ContentService $content
 */
class Ledge extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'health' => HealthService::class,
                'acquire' => AcquireService::class,
                'dependencies' => DependenciesService::class,
                'content' => ContentService::class,
            ],
        ];
    }

    /**
     * Effective settings, or the defaults when the plugin is not loaded (unit
     * tests exercise checks without a Craft application or plugin instance).
     */
    public static function currentSettings(): Settings
    {
        $instance = static::getInstance();

        return $instance !== null ? $instance->getSettings() : new Settings();
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function() {
            $this->registerRoutes();
            $this->registerConsoleCommands();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('ledge');

        return Settings::fromConfig(is_array($fileConfig) ? $fileConfig : []);
    }

    private function registerRoutes(): void
    {
        $settings = $this->getSettings();
        $endpointPath = $settings->endpointPath;
        $dependenciesPath = $settings->isDependenciesEnabled() ? $settings->dependenciesPath : null;
        $urisPath = $settings->isUrisEnabled() ? $settings->urisPath : null;
        $contentPath = $settings->isContentEnabled() ? $settings->contentPath : null;
        $acquirePath = $settings->isAcquireEnabled() ? $settings->acquirePath : null;

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) use ($endpointPath, $dependenciesPath, $urisPath, $contentPath, $acquirePath) {
                $event->rules[$endpointPath] = 'ledge/health/index';
                if ($dependenciesPath !== null) {
                    $event->rules["GET {$dependenciesPath}"] = 'ledge/dependencies/index';
                }

                if ($urisPath !== null) {
                    $event->rules["GET {$urisPath}"] = 'ledge/uris/index';
                }

                if ($contentPath !== null) {
                    $event->rules["GET {$contentPath}"] = 'ledge/content/index';
                }

                if ($acquirePath !== null) {
                    $event->rules["POST {$acquirePath}"] = 'ledge/acquire/index';
                    $event->rules["GET {$acquirePath}/<runId:[A-Za-z0-9\\-]{1,64}>"] = 'ledge/acquire/status';
                }
            }
        );
    }

    private function registerConsoleCommands(): void
    {
        if (Craft::$app instanceof ConsoleApplication) {
            Craft::$app->controllerMap['ledge'] = DefaultController::class;
        }
    }
}
