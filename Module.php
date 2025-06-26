<?php

namespace Sigil;

use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $settings->delete('sigil_asset_id');
        $settings->delete('sigil_gravity');
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $settings = $services->get('Omeka\Settings');

        $form = $formElementManager->get(Form\ConfigForm::class);
        $form->setData([
            'sigil_asset_id' => $settings->get('sigil_asset_id'),
            'sigil_gravity' => $settings->get('sigil_gravity', ''),
        ]);

        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $settings = $services->get('Omeka\Settings');

        $form = $formElementManager->get(Form\ConfigForm::class);
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            return false;
        }

        $data = $form->getData();
        $settings->set('sigil_asset_id', $data['sigil_asset_id'] ?: null);
        $settings->set('sigil_gravity', $data['sigil_gravity'] ?: null);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\File\TempFile',
            'media.ingest_file.pre',
            [$this, 'onMediaIngestFilePre']
        );
    }

    public function getConfig()
    {
        return require __DIR__ . '/config/module.config.php';
    }

    public function onMediaIngestFilePre (Event $event)
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $settings = $services->get('Omeka\Settings');
        $api = $services->get('Omeka\ApiManager');

        $sigil_asset_id = $settings->get('sigil_asset_id');
        $sigil_gravity = $settings->get('sigil_gravity');

        if (!$sigil_asset_id) {
            // Module is not configured
            return;
        }

        $tempFile = $event->getParam('tempFile');
        $mediaType = $tempFile->getMediaType() ?? '';
        if (!str_starts_with($mediaType, 'image/')) {
            $logger->debug(sprintf('Sigil: Media type ignored: "%s"', $mediaType));
            return;
        }

        $logger->info(sprintf('Sigil: Applying watermark to "%s"', $tempFile->getSourceName()));

        try {
            $asset = $api->read('assets', $sigil_asset_id)->getContent();
            $assetTempFile = $this->getAssetTempFile($asset);
        } catch (\Exception $e) {
            $logger->err(sprintf('Sigil: Failed to retrieve asset %d: %s', $sigil_asset_id, $e->getMessage()));
            return;
        }

        $destTempFile = $tempFileFactory->build();

        $command = sprintf(
            'composite %s %s %s %s',
            $sigil_gravity ? sprintf('-gravity %s', escapeshellarg($sigil_gravity)) : '',
            escapeshellarg($assetTempFile->getTempPath()),
            escapeshellarg($tempFile->getTempPath()),
            escapeshellarg($destTempFile->getTempPath())
        );
        $logger->debug(sprintf('Sigil: Executing command: %s', $command));
        $t0 = microtime(true);
        if (false === system($command, $exit_code)) {
            $logger->err(sprintf('Sigil: Failed to execute command: %s', $command));
            $destTempFile->delete();
            $assetTempFile->delete();

            return;
        }

        if ($exit_code !== 0) {
            $logger->err(sprintf('Sigil: Non-zero exit code (%d) for command: %s', $exit_code, $command));
            $destTempFile->delete();
            $assetTempFile->delete();

            return;
        }

        $elapsed = microtime(true) - $t0;
        $logger->debug(sprintf('Sigil: Execution time: %.3fs', $elapsed));

        if (false === rename($destTempFile->getTempPath(), $tempFile->getTempPath())) {
            $logger->err(sprintf('Sigil: Failed to rename %s to %s', $destTempFile->getTempPath(), $tempFile->getTempPath()));
            $destTempFile->delete();
            $assetTempFile->delete();

            return;
        }

        $assetTempFile->delete();
    }

    protected function getAssetTempFile(\Omeka\Api\Representation\AssetRepresentation $asset): \Omeka\File\TempFile
    {
        $services = $this->getServiceLocator();
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $fileStore = $services->get('Omeka\File\Store');
        $settings = $services->get('Omeka\Settings');

        $assetStoragePath = sprintf('asset/%s', $asset->filename());
        if ($fileStore instanceof \Omeka\File\Store\Local) {
            $assetLocalPath = $fileStore->getLocalPath($assetStoragePath);
            $assetTempFile = $tempFileFactory->build();
            if (false === copy($assetLocalPath, $assetTempFile->getTempPath())) {
                $assetTempFile->delete();
                throw new \Exception(sprintf('Failed to copy "%s" to "%s"', $assetLocalPath, $assetTempFile));
            }

            return $assetTempFile;
        }

        // File is not local, download it
        $downloader = $services->get('Omeka\File\Downloader');
        $assetUri = $fileStore->getUri($assetStoragePath);
        $errorStore = new \Omeka\Stdlib\ErrorStore;
        $assetTempFile = $downloader->download($assetUri, $errorStore);
        if (!$assetTempFile) {
            $errors = $errorStore->getErrors();
            $message = implode(', ', $errors);
            throw new \Exception($message);
        }

        return $assetTempFile;
    }
}
