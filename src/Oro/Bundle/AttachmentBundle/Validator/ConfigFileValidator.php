<?php

namespace Oro\Bundle\AttachmentBundle\Validator;

use Symfony\Component\Validator\Validator;
use Symfony\Component\Validator\Constraints\File as FileConstrain;

use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Entity\Attachment;

use Oro\Bundle\ConfigBundle\Config\UserConfigManager;

use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;

class ConfigFileValidator
{
    /** @var Validator */
    protected $validator;

    /** @var UserConfigManager */
    protected $config;

    /** @var ConfigProvider */
    protected $attachmentConfigProvider;

    /**
     * @param Validator         $validator
     * @param ConfigManager     $configManager
     * @param UserConfigManager $config
     */
    public function __construct(Validator $validator, ConfigManager $configManager, UserConfigManager $config)
    {
        $this->validator                = $validator;
        $this->attachmentConfigProvider = $configManager->getProvider('attachment');
        $this->config                   = $config;
    }

    /**
     * @param string          $dataClass Parent entity class name
     * @param File|Attachment $entity    File entity
     * @param string          $fieldName Field name where new file/image field was added
     *
     * @return \Symfony\Component\Validator\ConstraintViolationListInterface
     */
    public function validate($dataClass, $entity, $fieldName = '')
    {
        /** @var Config $entityAttachmentConfig */
        if ($fieldName === '') {
            $entityAttachmentConfig = $this->attachmentConfigProvider->getConfig($dataClass);
            $configValue            = 'upload_image_mime_types';
        } else {
            $entityAttachmentConfig = $this->attachmentConfigProvider->getConfig($dataClass, $fieldName);
            /** @var FieldConfigId $fieldConfigId */
            $fieldConfigId = $entityAttachmentConfig->getId();
            if ($fieldConfigId->getFieldType() === 'file') {
                $configValue = 'upload_mime_types';
            } else {
                $configValue = 'upload_image_mime_types';
            }
        }

        $fileSize  = $entityAttachmentConfig->get('maxsize') * 1024 * 1024;
        $mimeTypes = explode("\n", $this->config->get('oro_attachment.' . $configValue));
        foreach ($mimeTypes as $id => $value) {
            $mimeTypes[$id] = trim($value);
        }

        return $this->validator->validateValue(
            $entity->getFile(),
            [
                new FileConstrain(
                    [
                        'maxSize'   => $fileSize,
                        'mimeTypes' => $mimeTypes,
                        'mimeTypesMessage' => 'This file type is not allowed.'
                    ]
                )
            ]
        );
    }
}
