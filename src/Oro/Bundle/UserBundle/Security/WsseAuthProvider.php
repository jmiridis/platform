<?php

namespace Oro\Bundle\UserBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\PasswordEncoderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use Doctrine\Common\Cache\Cache;
use Doctrine\ORM\PersistentCollection;

use Escape\WSSEAuthenticationBundle\Security\Core\Authentication\Provider\Provider;

use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserApi;

/**
 * Class WsseAuthProvider
 * This override needed to use random generated API key for WSSE auth instead regular user password.
 * In order to prevent usage of user password in third party software.
 * In case if not ORO user is used this provider fallback to native behavior.
 *
 * @package Oro\Bundle\UserBundle\Security
 */
class WsseAuthProvider extends Provider
{
    /**
     * {@inheritdoc}
     */
    protected function getSecret(UserInterface $user)
    {
        if ($user instanceof AdvancedApiUserInterface) {
            return $user->getApiKeys();
        }

        return parent::getSecret($user);
    }

    /**
     * {@inheritdoc}
     */
    protected function getSalt(UserInterface $user)
    {
        if ($user instanceof AdvancedApiUserInterface) {
            return '';
        }

        return parent::getSalt($user);
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(TokenInterface $token)
    {
        /** @var User $user */
        $user = $this->getUserProvider()->loadUserByUsername($token->getUsername());
        if ($user) {
            $secret = $this->getSecret($user);
            if ($secret instanceof PersistentCollection) {
                /** @var $secret UserApi[] */
                foreach ($secret as $userApi) {
                    $isSecretValid = $this->validateDigest(
                        $token->getAttribute('digest'),
                        $token->getAttribute('nonce'),
                        $token->getAttribute('created'),
                        $userApi->getApiKey(),
                        $this->getSalt($user)
                    );
                    if ($isSecretValid) {
                        $authenticatedToken = new WsseToken($user->getRoles());
                        $authenticatedToken->setUser($user);
                        $authenticatedToken->setOrganizationContext($userApi->getOrganization());
                        $authenticatedToken->setAuthenticated(true);

                        return $authenticatedToken;
                    }
                }
            } else {
                return parent::authenticate($token);
            }
        }

        throw new AuthenticationException('WSSE authentication failed.');
    }
}
