<?php

namespace Oro\Bundle\EntityExtendBundle\Tools\DumperExtensions;

use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;

abstract class MultipleAssociationEntityConfigDumperExtension extends AbstractAssociationEntityConfigDumperExtension
{
    /**
     * {@inheritdoc}
     */
    protected function getAssociationType()
    {
        return 'manyToMany';
    }

    /**
     * {@inheritdoc}
     */
    protected function isTargetEntityApplicable(ConfigInterface $targetEntityConfig)
    {
        $entityClasses = $targetEntityConfig->get($this->getAssociationAttributeName());

        return !empty($entityClasses);
    }

    /**
     * {@inheritdoc}
     */
    public function preUpdate()
    {
        $targetEntityConfigs = $this->getTargetEntityConfigs();
        foreach ($targetEntityConfigs as $targetEntityConfig) {
            $entityClasses = $targetEntityConfig->get($this->getAssociationAttributeName());
            if (!empty($entityClasses)) {
                $targetEntityClass = $targetEntityConfig->getId()->getClassName();
                foreach ($entityClasses as $entityClass) {
                    $this->createAssociation($entityClass, $targetEntityClass);
                }
            }
        }
    }
}
