<?php

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Form\Type\AbandonedType;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Composer\Package\Version\VersionParser;
use DateTimeImmutable;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;

class PackageController extends Controller
{
    /**
     * @Template()
     * @Route(
     *     "/packages/{name}/edit",
     *     name="edit_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function editAction(Request $req, Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createFormBuilder($package, array("validation_groups" => array("Update")))
            ->add("repository", "text")
            ->getForm();

        if ($req->isMethod("POST")) {
            $form->bind($req);

            if ($form->isValid()) {
                // Force updating of packages once the package is viewed after the redirect.
                $package->setCrawledAt(null);

                $em = $this->getDoctrine()->getManager();
                $em->persist($package);
                $em->flush();

                $this->get("session")->getFlashBag()->set("success", "Changes saved.");

                return $this->redirect(
                    $this->generateUrl("view_package", array("name" => $package->getName()))
                );
            }
        }

        return array(
            "package" => $package, "form" => $form->createView()
        );
    }

    /**
     * @Route(
     *      "/packages/{name}/abandon",
     *      name="abandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     * @Template()
     */
    public function abandonAction(Request $request, Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createForm(new AbandonedType());
        if ($request->getMethod() === 'POST') {
            $form->bind($request->request->get('package'));
            if ($form->isValid()) {
                $package->setAbandoned(true);
                $package->setReplacementPackage(str_replace('https://packagist.org/packages/', '', $form->get('replacement')->getData()));
                $package->setIndexedAt(null);

                $em = $this->getDoctrine()->getManager();
                $em->flush();

                return $this->redirect($this->generateUrl('view_package', array('name' => $package->getName())));
            }
        }

        return array(
            'package' => $package,
            'form'    => $form->createView()
        );
    }

    /**
     * @Route(
     *      "/packages/{name}/unabandon",
     *      name="unabandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function unabandonAction(Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $package->setAbandoned(false);
        $package->setReplacementPackage(null);
        $package->setIndexedAt(null);

        $em = $this->getDoctrine()->getManager();
        $em->flush();

        return $this->redirect($this->generateUrl('view_package', array('name' => $package->getName())));
    }

    /**
     * @Route(
     *      "/packages/{name}/stats.{_format}",
     *      name="view_package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *      defaults={"_format"="html"}
     * )
     * @Template()
     */
    public function statsAction(Request $req, Package $package)
    {
        $versions = $package->getVersions()->toArray();
        usort($versions, Package::class.'::sortVersions');
        $date = $this->guessStatsStartDate($package);
        $data = [
            'downloads' => $this->get('packagist.download_manager')->getDownloads($package),
            'versions' => $versions,
            'average' => $this->guessStatsAverage($date),
            'date' => $date->format('Y-m-d'),
        ];

        if ($req->getRequestFormat() === 'json') {
            $data['versions'] = array_map(function ($version) {
                return $version->getVersion();
            }, $data['versions']);

            return new JsonResponse($data);
        }

        $data['package'] = $package;

        $expandedVersion = reset($versions);
        foreach ($versions as $v) {
            if (!$v->isDevelopment()) {
                $expandedVersion = $v;
                break;
            }
        }
        $data['expandedId'] = $expandedVersion ? $expandedVersion->getId() : false;

        return $data;
    }

    /**
     * @Route(
     *      "/packages/{name}/dependents",
     *      name="view_package_dependents",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     * @Template()
     */
    public function dependentsAction(Request $req, $name)
    {
        $page = $req->query->get('page', 1);

        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $depCount = $repo->getDependentCount($name);
        $packages = $repo->getDependents($name, ($page - 1) * 15, 15);

        $paginator = new Pagerfanta(new FixedAdapter($depCount, $packages));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page, false, true);

        $data['packages'] = $paginator;

        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['name'] = $name;

        return $data;
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/all.json",
     *      name="package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function overallStatsAction(Request $req, Package $package, Version $version = null)
    {
        if ($from = $req->query->get('from')) {
            $from = new DateTimeImmutable($from);
        } else {
            $from = $this->guessStatsStartDate($version ?: $package);
        }
        if ($to = $req->query->get('to')) {
            $to = new DateTimeImmutable($to);
        } else {
            $to = new DateTimeImmutable('-2days 00:00:00');
        }
        $average = $req->query->get('average', $this->guessStatsAverage($from, $to));

        $datePoints = $this->createDatePoints($from, $to, $average, $package, $version);

        $redis = $this->get('snc_redis.default');
        if ($average === 'Daily') {
            $datePoints = array_map(function ($vals) {
                return $vals[0];
            }, $datePoints);

            $datePoints = array(
                'labels' => array_keys($datePoints),
                'values' => $redis->mget(array_values($datePoints))
            );
        } else {
            $datePoints = array(
                'labels' => array_keys($datePoints),
                'values' => array_values(array_map(function ($vals) use ($redis) {
                    return array_sum($redis->mget(array_values($vals)));
                }, $datePoints))
            );
        }

        $datePoints['average'] = $average;

        if ($average !== 'daily') {
            $dividers = [
                'monthly' => 30.41,
                'weekly' => 7,
            ];
            $divider = $dividers[$average];
            $datePoints['values'] = array_map(function ($val) use ($divider) {
                return ceil($val / $divider);
            }, $datePoints['values']);
        }

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = 0;
        }

        $response = new JsonResponse($datePoints);
        $response->setSharedMaxAge(1800);

        return $response;
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/{version}.json",
     *      name="version_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "version"=".+?"}
     * )
     */
    public function versionStatsAction(Request $req, Package $package, $version)
    {
        $normalizer = new VersionParser;
        $normVersion = $normalizer->normalize($version);

        $version = $this->getDoctrine()->getRepository('PackagistWebBundle:Version')->findOneBy([
            'package' => $package,
            'normalizedVersion' => $normVersion
        ]);

        if (!$version) {
            throw new NotFoundHttpException();
        }

        return $this->overallStatsAction($req, $package, $version);
    }

    private function createDatePoints(DateTimeImmutable $from, DateTimeImmutable $to, $average, Package $package, Version $version = null)
    {
        $interval = $this->getStatsInterval($average);

        $dateKey = $average === 'monthly' ? 'Ym' : 'Ymd';
        $dateFormat = $average === 'monthly' ? 'Y-m' : 'Y-m-d';
        $dateJump = $average === 'monthly' ? '+1month' : '+1day';
        if ($average === 'monthly') {
            $to = new DateTimeImmutable('last day of '.$to->format('Y-m'));
        }

        $nextDataPointLabel = $from->format($dateFormat);
        $nextDataPoint = $from->modify($interval);

        $datePoints = [];
        while ($from <= $to) {
            $datePoints[$nextDataPointLabel][] = 'dl:'.$package->getId().($version ? '-' . $version->getId() : '').':'.$from->format($dateKey);

            $from = $from->modify($dateJump);
            if ($from >= $nextDataPoint) {
                $nextDataPointLabel = $from->format($dateFormat);
                $nextDataPoint = $from->modify($interval);
            }
        }

        return $datePoints;
    }

    private function guessStatsStartDate($packageOrVersion)
    {
        if ($packageOrVersion instanceof Package) {
            $date = DateTimeImmutable::createFromMutable($packageOrVersion->getCreatedAt());
        } elseif ($packageOrVersion instanceof Version) {
            $date = DateTimeImmutable::createFromMutable($packageOrVersion->getReleasedAt());
        } else {
            throw new \LogicException('Version or Package expected');
        }

        $statsRecordDate = new DateTimeImmutable('2012-04-13 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    private function guessStatsAverage(DateTimeImmutable $from, DateTimeImmutable $to = null)
    {
        if ($to === null) {
            $to = new DateTimeImmutable('-2 days');
        }
        if ($from < $to->modify('-48months')) {
            $average = 'monthly';
        } elseif ($from < $to->modify('-7months')) {
            $average = 'weekly';
        } else {
            $average = 'daily';
        }

        return $average;
    }

    private function getStatsInterval($average)
    {
        $intervals = [
            'monthly' => '+1month',
            'weekly' => '+7days',
            'daily' => '+1day',
        ];

        if (!isset($intervals[$average])) {
            throw new BadRequestHttpException();
        }

        return $intervals[$average];
    }
}
