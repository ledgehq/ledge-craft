<?php

declare(strict_types=1);

namespace ledgehq\craftledge\controllers;

use Craft;
use craft\web\Controller;
use ledgehq\craftledge\Ledge;
use Throwable;
use yii\web\Response;

class ContentController extends Controller
{
    use AuthenticatesWithSecretKey;

    protected array|bool|int $allowAnonymous = ['index'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        Craft::$app->getResponse()->setNoCacheHeaders();

        if (!Ledge::getInstance()->getSettings()->isContentEnabled()) {
            $response = Craft::$app->getResponse();
            $response->setStatusCode(404);
            $response->format = Response::FORMAT_JSON;
            $response->data = ['error' => 'Not enabled'];
            return false;
        }

        $error = $this->validateSecretKey();
        if ($error !== null) {
            return $this->rejectWithSecretKeyError($error);
        }

        return true;
    }

    public function actionIndex(): Response
    {
        try {
            $report = Ledge::getInstance()->content->getReport();
        } catch (Throwable $e) {
            Craft::error('Ledge content checks failed: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getResponse()->setStatusCode(503);

            return $this->asJson(['error' => 'Content checks are unavailable']);
        }

        return $this->asJson($report);
    }
}
