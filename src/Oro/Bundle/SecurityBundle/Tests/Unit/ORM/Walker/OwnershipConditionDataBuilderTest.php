<?php

namespace Oro\Bundle\SecurityBundle\Tests\Unit\ORM\Walker;

use Doctrine\ORM\Query\AST\PathExpression;

use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Acl\Domain\ObjectIdAccessor;
use Oro\Bundle\SecurityBundle\ORM\Walker\OwnershipConditionDataBuilder;
use Oro\Bundle\SecurityBundle\Owner\Metadata\OwnershipMetadata;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\Organization;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\User;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\OwnershipMetadataProviderStub;
use Oro\Bundle\SecurityBundle\Owner\OwnerTree;
use Oro\Bundle\SecurityBundle\Acl\Domain\OneShotIsGrantedObserver;

class OwnershipConditionDataBuilderTest extends \PHPUnit_Framework_TestCase
{
    const BUSINESS_UNIT = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\BusinessUnit';
    const ORGANIZATION = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\Organization';
    const USER = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\User';
    const TEST_ENTITY = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\TestEntity';

    /**
     * @var OwnershipConditionDataBuilder
     */
    private $builder;

    /** @var OwnershipMetadataProviderStub */
    private $metadataProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $securityContext;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $aclVoter;

    /** @var OwnerTree */
    private $tree;

    protected function setUp()
    {
        $this->tree = new OwnerTree();

        $treeProvider = $this->getMockBuilder('Oro\Bundle\SecurityBundle\Owner\OwnerTreeProvider')
            ->disableOriginalConstructor()
            ->getMock();

        $treeProvider->expects($this->any())
            ->method('getTree')
            ->will($this->returnValue($this->tree));

        $entityMetadataProvider =
            $this->getMockBuilder('Oro\Bundle\SecurityBundle\Metadata\EntitySecurityMetadataProvider')
                ->disableOriginalConstructor()
                ->getMock();
        $entityMetadataProvider->expects($this->any())
            ->method('isProtectedEntity')
            ->will($this->returnValue(true));

        $this->metadataProvider = new OwnershipMetadataProviderStub($this);
        $this->metadataProvider->setMetadata(
            $this->metadataProvider->getOrganizationClass(),
            new OwnershipMetadata()
        );
        $this->metadataProvider->setMetadata(
            $this->metadataProvider->getBusinessUnitClass(),
            new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner_id')
        );
        $this->metadataProvider->setMetadata(
            $this->metadataProvider->getUserClass(),
            new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner_id')
        );

        $this->securityContext = $this->getMock('Symfony\Component\Security\Core\SecurityContextInterface');
        $securityContextLink =
            $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\DependencyInjection\Utils\ServiceLink')
                ->disableOriginalConstructor()
                ->getMock();
        $securityContextLink->expects($this->any())->method('getService')
            ->will($this->returnValue($this->securityContext));
        $this->aclVoter = $this->getMockBuilder('Oro\Bundle\SecurityBundle\Acl\Voter\AclVoter')
            ->disableOriginalConstructor()
            ->getMock();

        $this->builder = new OwnershipConditionDataBuilder(
            $securityContextLink,
            new ObjectIdAccessor(),
            $entityMetadataProvider,
            $this->metadataProvider,
            $treeProvider,
            $this->aclVoter
        );
    }

    private function buildTestTree()
    {
        /**
         * org1  org2     org3         org4
         *                |            |
         *  bu1   bu2     +-bu3        +-bu4
         *        |       | |            |
         *        |       | +-bu31       |
         *        |       | | |          |
         *        |       | | +-user31   |
         *        |       | |            |
         *  user1 +-user2 | +-user3      +-user4
         *                |                |
         *                +-bu3a           +-bu3
         *                  |              +-bu4
         *                  +-bu3a1          |
         *                                   +-bu41
         *                                     |
         *                                     +-bu411
         *                                       |
         *                                       +-user411
         */
        $this->tree->addBusinessUnit('bu1', null);
        $this->tree->addBusinessUnit('bu2', null);
        $this->tree->addBusinessUnit('bu3', 'org3');
        $this->tree->addBusinessUnit('bu31', 'org3');
        $this->tree->addBusinessUnit('bu3a', 'org3');
        $this->tree->addBusinessUnit('bu3a1', 'org3');
        $this->tree->addBusinessUnit('bu4', 'org4');
        $this->tree->addBusinessUnit('bu41', 'org4');
        $this->tree->addBusinessUnit('bu411', 'org4');

        $this->tree->addBusinessUnitRelation('bu1', null);
        $this->tree->addBusinessUnitRelation('bu2', null);
        $this->tree->addBusinessUnitRelation('bu3', null);
        $this->tree->addBusinessUnitRelation('bu31', 'bu3');
        $this->tree->addBusinessUnitRelation('bu3a', null);
        $this->tree->addBusinessUnitRelation('bu3a1', 'bu3a');
        $this->tree->addBusinessUnitRelation('bu4', null);
        $this->tree->addBusinessUnitRelation('bu41', 'bu4');
        $this->tree->addBusinessUnitRelation('bu411', 'bu41');

        $this->tree->addUser('user1', null);
        $this->tree->addUser('user2', 'bu2');
        $this->tree->addUser('user3', 'bu3');
        $this->tree->addUser('user31', 'bu31');
        $this->tree->addUser('user4', 'bu4');
        $this->tree->addUser('user41', 'bu41');
        $this->tree->addUser('user411', 'bu411');

        $this->tree->addUserBusinessUnit('user4', 'org4', 'bu3');
        $this->tree->addUserBusinessUnit('user4', 'org4', 'bu4');

        $this->tree->addUserOrganization('user4', 'org4');
    }

    /**
     * @dataProvider buildFilterConstraintProvider
     */
    public function testGetAclConditionData(
        $userId,
        $organizationId,
        $isGranted,
        $accessLevel,
        $ownerType,
        $targetEntityClassName,
        $expectedConstraint
    ) {
        $this->buildTestTree();

        if ($ownerType !== null) {
            $this->metadataProvider->setMetadata(
                self::TEST_ENTITY,
                new OwnershipMetadata($ownerType, 'owner', 'owner_id', 'organization', 'organization_id')
            );
        }

        /** @var OneShotIsGrantedObserver $aclObserver */
        $aclObserver = null;
        $this->aclVoter->expects($this->any())
            ->method('addOneShotIsGrantedObserver')
            ->will(
                $this->returnCallback(
                    function ($observer) use (&$aclObserver, &$accessLevel) {
                        $aclObserver = $observer;
                        /** @var OneShotIsGrantedObserver $aclObserver */
                        $aclObserver->setAccessLevel($accessLevel);
                    }
                )
            );

        $user = new User($userId);
        $organization = new Organization($organizationId);
        $user->addOrganization($organization);
        $token =
            $this->getMockBuilder('Oro\Bundle\SecurityBundle\Authentication\Token\UsernamePasswordOrganizationToken')
            ->disableOriginalConstructor()
            ->getMock();

        $token->expects($this->any())
            ->method('getUser')
            ->will($this->returnValue($user));
        $token->expects($this->any())
            ->method('getOrganizationContext')
            ->will($this->returnValue($organization));
        $this->securityContext->expects($this->any())
            ->method('isGranted')
            ->with($this->equalTo('VIEW'), $this->equalTo('entity:' . $targetEntityClassName))
            ->will($this->returnValue($isGranted));
        $this->securityContext->expects($this->any())
            ->method('getToken')
            ->will($this->returnValue($userId ? $token : null));

        $result = $this->builder->getAclConditionData($targetEntityClassName);

        $this->assertEquals(
            $expectedConstraint,
            $result
        );
    }

    public function testGetUserIdWithNonLoginUser()
    {
        $token = $this->getMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');
        $token->expects($this->any())
            ->method('getUser')
            ->will($this->returnValue('anon'));
        $this->securityContext->expects($this->any())
            ->method('getToken')
            ->will($this->returnValue($token));
        $this->assertNull($this->builder->getUserId());
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function buildFilterConstraintProvider()
    {
        return array(
            'for the TEST entity without userId, grant, ownerType; with NONE ACL' => array(
                '', '', false, AccessLevel::NONE_LEVEL, null, self::TEST_ENTITY, []
            ),
            'for the stdClass entity without userId, grant, ownerType; with NONE ACL' => array(
                '', '', false, AccessLevel::NONE_LEVEL, null, '\stdClass', []
            ),
            'for the stdClass entity without userId, ownerType; with grant and NONE ACL' => array(
                '', '', true, AccessLevel::NONE_LEVEL, null, '\stdClass', []
            ),
            'for the TEST entity without ownerType; with SYSTEM ACL, userId, grant' => array(
                'user4', '', true, AccessLevel::SYSTEM_LEVEL, null, self::TEST_ENTITY, []
            ),
            'for the TEST entity with SYSTEM ACL, userId, grant and ORGANIZATION ownerType' => array(
                'user4', '', true, AccessLevel::SYSTEM_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, []
            ),
            'for the TEST entity with SYSTEM ACL, userId, grant and BUSINESS_UNIT ownerType' => array(
                'user4', '', true, AccessLevel::SYSTEM_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, []
            ),
            'for the TEST entity with SYSTEM ACL, userId, grant and USER ownerType' => array(
                'user4', '', true, AccessLevel::SYSTEM_LEVEL, 'USER', self::TEST_ENTITY, []
            ),
            'for the TEST entity without ownerType; with GLOBAL ACL, userId, grant' => array(
                'user4', '', true, AccessLevel::GLOBAL_LEVEL, null, self::TEST_ENTITY, []
            ),
            'for the TEST entity with GLOBAL ACL, userId, grant and ORGANIZATION ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                'ORGANIZATION',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('org4'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the TEST entity with GLOBAL ACL, userId, grant and BUSINESS_UNIT ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                'BUSINESS_UNIT',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('bu4', 'bu41', 'bu411'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the TEST entity with GLOBAL ACL, userId, grant and USER ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                'USER',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('user4', 'user41', 'user411'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the ORGANIZATION entity without ownerType; with GLOBAL ACL, userId, grant' => array(
                'user4',
                'org4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                null,
                self::ORGANIZATION,
                array(
                    'id',
                    array('org4'),
                    PathExpression::TYPE_STATE_FIELD,
                    null,
                    null
                )
            ),
            'for the TEST entity without ownerType; with DEEP ACL, userId, grant' => array(
                'user4', '', true, AccessLevel::DEEP_LEVEL, null, self::TEST_ENTITY, []
            ),
            'for the TEST entity with DEEP ACL, userId, grant and ORGANIZATION ownerType' => array(
                'user4', '', true, AccessLevel::DEEP_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, null
            ),
            'for the TEST entity with DEEP ACL, userId, grant and BUSINESS_UNIT ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::DEEP_LEVEL,
                'BUSINESS_UNIT',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('bu4', 'bu41', 'bu411'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the TEST entity with DEEP ACL, userId, grant and USER ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::DEEP_LEVEL,
                'USER',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('user4', 'user41', 'user411'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the BUSINESS entity without ownerType; with DEEP ACL, userId, grant' => array(
                'user4',
                'org4',
                true,
                AccessLevel::DEEP_LEVEL,
                null,
                self::BUSINESS_UNIT,
                array(
                    'id',
                    array('bu4', 'bu41', 'bu411'),
                    PathExpression::TYPE_STATE_FIELD,
                    null,
                    null
                )
            ),
            'for the TEST entity without ownerType; with LOCAL ACL, userId, grant' => array(
                'user4', '', true, AccessLevel::LOCAL_LEVEL, null, self::TEST_ENTITY, []
            ),
            'for the TEST entity with LOCAL ACL, userId, grant and ORGANIZATION ownerType' => array(
                'user4', '', true, AccessLevel::LOCAL_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, null
            ),
            'for the TEST entity with LOCAL ACL, userId, grant and BUSINESS_UNIT ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::LOCAL_LEVEL,
                'BUSINESS_UNIT',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('bu4'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the TEST entity with LOCAL ACL, userId, grant and USER ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::LOCAL_LEVEL,
                'USER',
                self::TEST_ENTITY,
                array(
                    'owner',
                    array('user4'),
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the BUSINESS entity without ownerType; with LOCAL ACL, userId, grant' => array(
                'user4',
                'org4',
                true,
                AccessLevel::LOCAL_LEVEL,
                null,
                self::BUSINESS_UNIT,
                array(
                    'id',
                    array('bu4'),
                    PathExpression::TYPE_STATE_FIELD,
                    null,
                    null
                )
            ),
            'for the TEST entity without ownerType; with BASIC ACL, userId, grant' => array(
                'user4', '', true, AccessLevel::BASIC_LEVEL, null, self::TEST_ENTITY, []
            ),
            'for the TEST entity with BASIC ACL, userId, grant and ORGANIZATION ownerType' => array(
                'user4', '', true, AccessLevel::BASIC_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, null
            ),
            'for the TEST entity with BASIC ACL, userId, grant and BUSINESS_UNIT ownerType' => array(
                'user4', '', true, AccessLevel::BASIC_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, null
            ),
            'for the TEST entity with BASIC ACL, userId, grant and USER ownerType' => array(
                'user4',
                'org4',
                true,
                AccessLevel::BASIC_LEVEL,
                'USER',
                self::TEST_ENTITY,
                array(
                    'owner',
                    'user4',
                    PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                    'organization',
                    'org4'
                )
            ),
            'for the USER entity without ownerType; with BASIC ACL, userId, grant' => array(
                'user4',
                'org4',
                true,
                AccessLevel::BASIC_LEVEL,
                null,
                self::USER,
                array(
                    'id',
                    'user4',
                    PathExpression::TYPE_STATE_FIELD,
                    null,
                    null
                )
            ),
            'TEST entity with BASIC ACL, userId without related BU, grant and BUSINESS_UNIT ownerType' => array(
                'user1', '', true, AccessLevel::BASIC_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, null
            ),
            'TEST entity with LOCAL ACL, userId without related BU, grant and BUSINESS_UNIT ownerType' => array(
                'user1', '', true, AccessLevel::LOCAL_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, null
            ),
            'BUSINESS entity with LOCAL ACL, userId without related BU, grant, BUSINESS_UNIT ownerType' => array(
                'user1', '', true, AccessLevel::LOCAL_LEVEL, 'BUSINESS_UNIT', self::BUSINESS_UNIT, null
            ),
            'USER entity with LOCAL ACL, userId without related BU, grant, BUSINESS_UNIT ownerType' => array(
                'user1', '', true, AccessLevel::LOCAL_LEVEL, 'BUSINESS_UNIT', self::USER, null
            ),
            'TEST entity with DEEP ACL, userId without related BU, grant and BUSINESS_UNIT ownerType' => array(
                'user1', '', true, AccessLevel::DEEP_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, null
            ),
            'TEST entity with GLOBAL ACL, userId without related BU, grant and BUSINESS_UNIT ownerType' => array(
                'user1', '', true, AccessLevel::GLOBAL_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, null
            ),
        );
    }
}
