<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DashboardSummary;
use App\Entity\User;
use App\Services\DashboardService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

final class DashboardController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * A single JSON payload for the whole dashboard screen, rather than
     * several endpoints the frontend would have to orchestrate. Requires authentication (see security.yaml); the
     * dashboard is scoped to the logged-in user's own organisation, not
     * just "whichever organisation happens to exist".
     */
    #[Route(path: '/api/dashboard', name: 'api_dashboard', methods: ['GET'])]
    #[OA\Tag(name: 'Dashboard')]
    #[OA\Parameter(
        name: 'date_from',
        description: 'Reference date (Y-m-d) the rolling salesWindow figures end on. Defaults to the latest activity in the seeded data.',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string', format: 'date', example: '2026-06-20'),
    )]
    #[OA\Response(response: 200, description: 'The dashboard summary.', content: new OA\JsonContent(ref: new Model(type: DashboardSummary::class)))]
    #[OA\Response(response: 400, description: 'date_from is not a valid date.')]
    #[OA\Response(response: 401, description: 'Missing or invalid bearer token.')]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $organisation = $user->getOrganisation();

        try {
            // date_from must be read inside this try block, not before it —
            // InputBag::get() itself throws (e.g. ?date_from[]=... is a
            // non-scalar value) on exactly the same "bad input" footing as
            // an unparseable date string below.
            //
            // Defaults to the latest activity in the data, not wall-clock
            // "now" — the fixture's sales window is long past, and real
            // "now" would make every rolling window show zero.
            $dateFromParam = $request->query->get('date_from');
            $dateFrom = $dateFromParam !== null
                ? new \DateTimeImmutable($dateFromParam)
                : ($this->dashboardService->findLatestActivityDate($organisation) ?? new \DateTimeImmutable());
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid date_from date.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $summary = $this->dashboardService->summarize($organisation, $dateFrom);

        return $this->toJsonResponse($summary);
    }

    /**
     * The action method itself must return Response (Symfony's HTTP
     * contract), so this is as close to the boundary as a DTO can be
     * type-hinted — the serialization step is explicitly against
     * DashboardSummary rather than an untyped inline call.
     */
    private function toJsonResponse(DashboardSummary $summary): JsonResponse
    {
        return JsonResponse::fromJsonString($this->serializer->serialize($summary, 'json'));
    }
}
