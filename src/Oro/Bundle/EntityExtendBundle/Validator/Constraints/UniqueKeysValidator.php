<?php

namespace Oro\Bundle\EntityExtendBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

use Oro\Bundle\UIBundle\Tools\ArrayUtils;

class UniqueKeysValidator extends ConstraintValidator
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint)
    {
        if (empty($value)) {
            return;
        }

        /** @var UniqueKeys $constraint */
        $names = ArrayUtils::arrayColumn($value, 'name');
        if ($names != array_unique($names)) {
            $this->context->addViolation(
                $constraint->message
            );

            return;
        }

        $keys = ArrayUtils::arrayColumn($value, 'key');
        if ($keys != array_unique($keys, SORT_REGULAR)) {
            $this->context->addViolation(
                $constraint->message
            );

            return;
        }
    }
}
