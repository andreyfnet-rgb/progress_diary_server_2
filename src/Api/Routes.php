<?php

declare(strict_types=1);

namespace Gdpd\Api;

use Gdpd\Domain\AuthService;
use Gdpd\Domain\CalendService;
use Gdpd\Domain\GenericTableService;
use Gdpd\Domain\InRecPdService;
use Gdpd\Domain\Lookups\DanceService;
use Gdpd\Domain\Lookups\LookupService;
use Gdpd\Domain\LvlStatService;
use Gdpd\Domain\ProcEndService;
use Gdpd\Domain\PurposeService;
use Gdpd\Domain\SalaryService;

/**
 * The full route table from the legacy WbMdul.dfm action list, kept in one
 * place so it's easy to check off against the original as endpoints are
 * ported. See the plan doc for the priority order this was actually
 * implemented in (driven by what the real dp.galladance.com/galladance.com
 * code calls, not just the original apicommand's own order).
 */
final class Routes
{
    /**
     * Method restriction per route name, matching what WbMdul.dfm
     * declares: 'get'/'put' where the legacy action had an explicit
     * MethodType (so WebBroker itself refuses the other method before ever
     * reaching apicommand), 'any' where it didn't (mtAny -- the route
     * reaches the dispatcher regardless of method, and it's the
     * dispatcher's own GET/PUT branching, mirroring apicommand, that
     * decides what happens, including the echo-the-body-back fallback for
     * e.g. POST).
     *
     * @return array<string, 'get'|'put'|'any'>
     */
    public static function methodRestrictions(): array
    {
        return [
            'prepod' => 'get',
            'client' => 'get',
            'lesstype' => 'get',
            'dance' => 'get',
            'putdattab' => 'put',
            'lessobj' => 'any',
            'inrecpd' => 'any',
            'inrecpd_dat' => 'any', // present in WbMdul.dfm but never handled by apicommand -- kept as a no-op route for parity.
            'figura' => 'any',
            'lesswrk' => 'any',
            'purpose' => 'any',
            'author' => 'any',
            'getpass' => 'any',
            'calend' => 'any',
            'getdattab' => 'any',
            'lvlstat' => 'any',
            'procend' => 'any',
            'getdatsal' => 'any',
            'getactsal' => 'any',
        ];
    }

    public static function register(
        Dispatcher $dispatcher,
        GenericTableService $genericTableService,
        LookupService $lookupService,
        DanceService $danceService,
        AuthService $authService,
        PurposeService $purposeService,
        CalendService $calendService,
        ProcEndService $procEndService,
        SalaryService $salaryService,
        InRecPdService $inRecPdService,
        LvlStatService $lvlStatService,
    ): void {
        $dispatcher->mapGet('getdattab', function (?array $jo) use ($genericTableService): string {
            $tab = (string) ($_GET['tab'] ?? '');
            return $genericTableService->getDatTab($tab, $jo);
        });

        $dispatcher->mapPutArray('putdattab', function (array $ja) use ($genericTableService): string {
            $tab = (string) ($_GET['tab'] ?? '');
            return $genericTableService->putDatTab($tab, $ja);
        });

        $dispatcher->mapGet('prepod', fn(?array $jo): string => $lookupService->getPrepod($jo));
        $dispatcher->mapGet('client', fn(?array $jo): string => $lookupService->getClient($jo));
        $dispatcher->mapGet('lesstype', fn(?array $jo): string => $lookupService->getLessType($jo));
        $dispatcher->mapGet('figura', fn(?array $jo): string => $lookupService->getFigura($jo));
        $dispatcher->mapGet('lessobj', fn(?array $jo): string => $lookupService->getLessObj($jo));
        $dispatcher->mapGet('lesswrk', fn(?array $jo): string => $lookupService->getLessWrk($jo));
        $dispatcher->mapGet('dance', fn(?array $jo): string => $danceService->getDance($jo));
        $dispatcher->mapGet('author', fn(?array $jo): string => $authService->getAuthor($jo));
        $dispatcher->mapGet('getpass', fn(?array $jo): string => $authService->getPass($jo));
        $dispatcher->mapGet('purpose', fn(?array $jo): string => $purposeService->getPurpose($jo));
        $dispatcher->mapPutObject('purpose', fn(?array $jo): string => $purposeService->putPurpose($jo ?? []));
        $dispatcher->mapGet('calend', fn(?array $jo): string => $calendService->getCalend($jo));
        $dispatcher->mapGet('procend', fn(?array $jo): string => $procEndService->getProcEnd($jo));
        $dispatcher->mapGet('getdatsal', fn(?array $jo): string => $salaryService->getDatSal($jo));
        $dispatcher->mapGet('inrecpd', fn(?array $jo): string => $inRecPdService->getInRecPd($jo));
        $dispatcher->mapPutObject('inrecpd', fn(?array $jo): string => $inRecPdService->putInRecPd($jo ?? []));
        $dispatcher->mapGet('lvlstat', fn(?array $jo): string => $lvlStatService->getLvlStat($jo));

        // getactsal (and getdatashow0722, its complex salary-formula
        // engine) is not ported -- no confirmed live consumer was found
        // for it (see SalaryService's docblock), and it carries
        // meaningfully higher risk to get right without a way to compare
        // against the real old server's output on real salary data. Until
        // then it falls through to Dispatcher's echo-the-body behaviour,
        // same as an unmatched mtAny route in the original.
    }
}
