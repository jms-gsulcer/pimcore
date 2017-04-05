<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\CoreBundle\EventListener\Frontend;

use Pimcore\Config;
use Pimcore\Http\RequestHelper;
use Pimcore\Model\Site;
use Pimcore\Routing\RedirectHandler;
use Pimcore\Service\Request\PimcoreContextResolver;
use Pimcore\Tool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs before dynamic routing kicks in and resolves site + handles redirects
 */
class FrontendRoutingListener extends AbstractFrontendListener implements EventSubscriberInterface
{
    /**
     * @var RequestHelper
     */
    protected $requestHelper;

    /**
     * @var RedirectHandler
     */
    protected $redirectHandler;

    /**
     * @param RequestHelper $requestHelper
     * @param RedirectHandler $redirectHandler
     */
    public function __construct(RequestHelper $requestHelper, RedirectHandler $redirectHandler)
    {
        $this->requestHelper   = $requestHelper;
        $this->redirectHandler = $redirectHandler;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            // run with high priority as we need to set the site early
            KernelEvents::REQUEST   => ['onKernelRequest', 512],

            // run with high priority before handling real errors
            KernelEvents::EXCEPTION => ['onKernelException', 64]
        ];
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if (!$this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_DEFAULT)) {
            return;
        }

        $path = urldecode($request->getPathInfo());

        // TODO http_auth omitted -> handle in security component

        // resolve current site from request
        $this->resolveSite($request, $path);

        // check for override redirects
        $response = $this->redirectHandler->checkForRedirect($request, true);
        if ($response) {
            $event->setResponse($response);

            return;
        }

        // check for app.php in URL and remove it for SEO puroposes
        $this->handleFrontControllerRedirect($event, $path);
        if ($event->hasResponse()) {
            return;
        }

        // redirect to the main domain if specified
        $this->handleMainDomainRedirect($event);
        if ($event->hasResponse()) {
            return;
        }
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        // in case routing didn't find a matching route, check for redirects without override
        $exception = $event->getException();
        if ($exception instanceof NotFoundHttpException) {
            $response = $this->redirectHandler->checkForRedirect($event->getRequest(), false);
            if ($response) {
                $event->setResponse($response);
            }
        }
    }

    /**
     * Initialize Site
     *
     * @param Request $request
     * @param $path
     *
     * @return string
     */
    protected function resolveSite(Request $request, $path)
    {
        // check for a registered site
        // do not initialize a site if it is a "special" admin request
        if (!$this->requestHelper->isFrontendRequestByAdmin($request)) {
            // host name without port incl. X-Forwarded-For handling for trusted proxies
            $host = $request->getHost();

            try {
                $site = Site::getByDomain($host);
                $path = $site->getRootPath() . $path;

                Site::setCurrentSite($site);

                $request->attributes->set('_site', $site);
                $request->attributes->set('_site_path', $path);
            } catch (\Exception $e) {
                // noop - execption is logged in getByDomain
            }
        }

        return $path;
    }

    /**
     * @param GetResponseEvent $event
     * @param string $path
     */
    protected function handleFrontControllerRedirect(GetResponseEvent $event, $path)
    {
        $request = $event->getRequest();

        // do not allow requests including /app.php/ => SEO
        // this is after the first redirect check, to allow redirects in app.php?xxx
        if (preg_match("@^/app.php(.*)@", $path, $matches) && $request->getMethod() === 'GET') {
            $redirectUrl = $matches[1];
            $redirectUrl = ltrim($redirectUrl, "/");
            $redirectUrl = "/" . $redirectUrl;

            $event->setResponse(new RedirectResponse($redirectUrl, Response::HTTP_MOVED_PERMANENTLY));
        }
    }

    /**
     * Redirect to the main domain if specified
     *
     * @param GetResponseEvent $event
     */
    protected function handleMainDomainRedirect(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        $config  = Config::getSystemConfig();

        $hostRedirect = null;

        if (Site::isSiteRequest()) {
            $site = Site::getCurrentSite();
            if ($site->getRedirectToMainDomain() && $site->getMainDomain() != $request->getHost()) {
                $hostRedirect = $site->getMainDomain();
            }
        } else {
            $gc = $config->general;
            if ($gc->redirect_to_maindomain && $gc->domain && !$this->requestHelper->isFrontendRequestByAdmin()) {
                if ($config->general->domain != $request->getHost()) {
                    $hostRedirect = $config->general->domain;
                }
            }
        }

        if ($hostRedirect && $request->request->has('pimcore_disable_host_redirect')) {
            $qs = '';
            if (null !== $qs = $request->getQueryString()) {
                $qs = '?' . $qs;
            }

            $url = $request->getScheme() . '://' . $hostRedirect . $request->getBaseUrl() . $request->getPathInfo() . $qs;

            // TODO use symfony logger service
            // log all redirects to the redirect log
            \Pimcore\Log\Simple::log('redirect', Tool::getAnonymizedClientIp() . " \t Host-Redirect Source: " . $request->getRequestUri() . " -> " . $url);

            $event->setResponse(new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY));
        }
    }
}