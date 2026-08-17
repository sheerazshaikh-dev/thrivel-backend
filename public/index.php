<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/advisor.php';
require dirname(__DIR__) . '/src/body_profile.php';
cors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) $path = substr($path, strlen($scriptDir)) ?: '/';
if (str_starts_with($path, '/api/')) $path = substr($path, 4);
$path = '/' . trim($path, '/');

function answer_value(array $assessment, string $key, mixed $default = ''): mixed {
    $answer = $assessment[$key] ?? null;
    if (is_array($answer) && array_key_exists('value', $answer)) return $answer['value'];
    return $answer ?? $default;
}

function normalize_match_tag(string $value): string {
    $value = strtolower(str_replace('&', ' and ', $value));
    $value = preg_replace('/[^a-z0-9+]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function add_match_intent(array &$intents, string $tag, float $weight, string $source): void {
    $key = normalize_match_tag($tag);
    if ($key === '') return;
    if (!isset($intents[$key])) $intents[$key] = ['weight' => $weight, 'sources' => [$source]];
    else {
        $intents[$key]['weight'] = max((float)$intents[$key]['weight'], $weight);
        if (!in_array($source, $intents[$key]['sources'], true)) $intents[$key]['sources'][] = $source;
    }
}

function build_match_intents(array $assessment): array {
    $primaryMap = [
        'Weight management' => ['weight loss','appetite reduction','hunger control','satiety','reduced food intake','steady weight loss','fat metabolism','stored fat use','blood sugar balance','blood sugar support','belly fat reduction','body composition','insulin response'],
        'Recovery & healing' => ['recovery','injury recovery','tendon healing','tendon recovery','tendon repair','ligament repair','muscle healing','muscle recovery','muscle tear recovery','soft tissue repair','joint recovery','deep tissue renewal','full-body recovery','gut support','gut lining support','inflammation support','systemic inflammation','wound healing'],
        'Longevity' => ['longevity','anti-aging','cellular aging','cellular health','dna protection','dna repair','skin renewal','collagen support','skin tightening','hair growth'],
        'Performance' => ['performance','growth hormone support','lean muscle','body composition','muscle recovery','physical stress response','cellular energy','energy production','mental focus','focus'],
        'Energy' => ['energy','cellular energy','energy production','mitochondrial energy','mental clarity','physical stress response'],
    ];
    $secondaryMap = [
        'Better sleep' => ['better sleep','sleep quality','sleep support','melatonin regulation'],
        'Muscle recovery' => ['muscle recovery','muscle healing','muscle tear recovery','recovery','soft tissue repair','full-body recovery'],
        'Focus' => ['focus','mental focus','mental clarity','memory','cognitive enhancement'],
        'Immune support' => ['inflammation support','systemic inflammation','gut inflammation'],
        'Skin quality' => ['skin quality','skin renewal','skin tightening','collagen support','hair growth'],
    ];

    $intents = [];
    $primary = trim((string)answer_value($assessment, 'primary_goal', 'Weight management')) ?: 'Weight management';
    add_match_intent($intents, $primary, 12, "Primary goal: {$primary}");
    foreach (($primaryMap[$primary] ?? []) as $index => $tag) add_match_intent($intents, $tag, max(6, 11 - $index), "Primary goal: {$primary}");

    $secondary = answer_value($assessment, 'secondary_goals', []);
    if (!is_array($secondary)) $secondary = [];
    foreach ($secondary as $goalValue) {
        $goal = (string)$goalValue;
        add_match_intent($intents, $goal, 7, "Secondary goal: {$goal}");
        foreach (($secondaryMap[$goal] ?? []) as $tag) add_match_intent($intents, $tag, 6, "Secondary goal: {$goal}");
    }

    $sleep = (float)answer_value($assessment, 'sleep_quality', 0);
    if ($sleep > 0 && $sleep <= 5) foreach (['better sleep','sleep quality','sleep support','melatonin regulation'] as $tag) add_match_intent($intents, $tag, 6, 'Low sleep score');

    $training = (string)answer_value($assessment, 'training_freq', '');
    if (in_array($training, ['5-6','7+'], true)) foreach (['muscle recovery','recovery','lean muscle','physical stress response'] as $tag) add_match_intent($intents, $tag, 5, 'High weekly training frequency');

    $activity = (float)answer_value($assessment, 'activity_level', 0);
    if ($activity >= 7) foreach (['performance','recovery','cellular energy','physical stress response'] as $tag) add_match_intent($intents, $tag, 4, 'High activity level');
    body_profile_add_match_intents($intents, $assessment);
    return $intents;
}

function product_match_score(array $row, array $intents): array {
    $score = 0.0;
    $matched = [];
    $goalTags = decode_json_array($row['goal_tags'] ?? null);
    $useCases = decode_json_array($row['use_cases'] ?? null);
    $candidateTags = [];
    foreach ($goalTags as $tag) $candidateTags[] = ['value' => (string)$tag, 'multiplier' => 1.45];
    foreach ($useCases as $tag) $candidateTags[] = ['value' => (string)$tag, 'multiplier' => 1.0];
    $candidateTags[] = ['value' => (string)$row['category'], 'multiplier' => 0.65];

    foreach ($candidateTags as $candidate) {
        $normalized = normalize_match_tag($candidate['value']);
        if ($normalized === '') continue;
        foreach ($intents as $intentTag => $intent) {
            $match = 0.0;
            if ($normalized === $intentTag) $match = 1.0;
            elseif (strlen($normalized) >= 5 && strlen($intentTag) >= 5 && (str_contains($normalized, $intentTag) || str_contains($intentTag, $normalized))) $match = 0.55;
            if ($match <= 0) continue;
            $points = (float)$intent['weight'] * (float)$candidate['multiplier'] * $match;
            $score += $points;
            $matched[$candidate['value']] = max((float)($matched[$candidate['value']] ?? 0), $points);
        }
    }
    arsort($matched);
    return ['score' => $score, 'reasons' => array_slice(array_keys($matched), 0, 3)];
}

function latest_order_for_user(int $userId): ?array {
    $stmt = db()->prepare('SELECT o.*, p.slug AS stack_slug FROM orders o LEFT JOIN products p ON p.id = o.stack_product_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_member_plan_for_order(int $userId, array $order): array {
    $assessment = json_decode((string)($order['assessment_json'] ?? '{}'), true) ?: [];
    $goal = trim((string)answer_value($assessment, 'primary_goal', 'General wellness')) ?: 'General wellness';
    $training = (string)answer_value($assessment, 'training_freq', '3-4');
    $diet = (string)answer_value($assessment, 'diet', 'None');
    $currentMedications = trim((string)answer_value($assessment, 'current_medications', ''));

    $items = decode_json_array($order['items_json'] ?? null);
    if (!$items && !empty($order['stack_product_id'])) {
        $stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$order['stack_product_id']]);
        $legacy = $stmt->fetch();
        if ($legacy) {
            $items = [[
                'id' => (string)$legacy['slug'],
                'name' => (string)$legacy['name'],
                'category' => (string)$legacy['category'],
                'price' => (float)$legacy['price'],
            ]];
        }
    }

    $productIds = array_values(array_filter(array_map(static fn(array $item): string => trim((string)($item['id'] ?? '')), array_filter($items, 'is_array'))));
    $productNames = array_values(array_filter(array_map(static fn(array $item): string => trim((string)($item['name'] ?? '')), array_filter($items, 'is_array'))));
    $categories = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['category'] ?? '')), array_filter($items, 'is_array')))));

    $firstProduct = null;
    if ($productIds) {
        $firstStmt = db()->prepare('SELECT * FROM products WHERE slug = ? LIMIT 1');
        $firstStmt->execute([$productIds[0]]);
        $firstProduct = $firstStmt->fetch() ?: null;
    }

    $workout = match ($training) {
        '0' => ['Day 1: Goblet squat 3×10, incline push-up 3×8, seated cable row 3×10', 'Day 3: Leg press 3×10, dumbbell bench press 3×10, lat pulldown 3×10', 'Two 20-minute walks plus daily 5-minute mobility'],
        '1-2' => ['Day 1: Bench press 3×8, Romanian deadlift 3×10, seated row 3×10', 'Day 3: Leg press 3×10, split squat 3×8/leg, overhead press 3×8', 'Two 25-minute zone-2 sessions plus one recovery mobility session'],
        '5-6', '7+' => ['Upper: Bench press 4×6-8, row 4×8, overhead press 3×8', 'Lower: Squat or leg press 4×6-10, Romanian deadlift 3×8, leg curl 3×10', 'Two additional structured sessions, two zone-2/recovery sessions, one complete rest day'],
        default => ['Day 1: Bench press 3×8, seated row 3×10, lateral raise 3×12', 'Day 3: Squat or leg press 3×8-10, Romanian deadlift 3×10, leg curl 3×12', 'Day 5: Incline dumbbell press 3×10, lat pulldown 3×10, walking lunges 3×10/leg', 'Two zone-2 cardio sessions and one mobility/recovery session'],
    };

    $plantBased = in_array($diet, ['Vegetarian', 'Vegan'], true);
    $mealPlan = $plantBased
        ? ['Include a complete plant protein at every meal', 'Use two servings of vegetables at lunch and dinner', 'Place high-fiber carbohydrates around training', 'Water target: 2-3 liters daily']
        : ['Use a protein-forward breakfast', 'Build lunch around lean protein and vegetables', 'Keep dinner balanced with controlled portions', 'Water target: 2-3 liters daily'];

    $vitamins = [
        ['name' => 'Vitamin D3', 'dosage' => 'Reviewer assigned after history or labs', 'note' => 'Do not start based on automated guidance alone.'],
        ['name' => 'Magnesium glycinate', 'dosage' => 'Reviewer assigned', 'note' => 'Medication interactions and kidney history must be checked first.'],
        ['name' => 'Omega-3', 'dosage' => 'Reviewer assigned', 'note' => 'Bleeding risk and current medications must be reviewed first.'],
    ];

    $targets = ['Complete the planned workouts', 'Log weight once weekly', 'Average at least 7 hours of sleep', 'Complete one dashboard check-in'];
    $packageName = $productNames ? implode(', ', $productNames) : 'AI Health Coach only';
    $medication = trim((string)($firstProduct['medication'] ?? '')) ?: ($firstProduct ? 'Reviewer selected' : 'No medication selected');
    $dosage = trim((string)($firstProduct['dosage'] ?? '')) ?: ($firstProduct ? 'Reviewer assigned' : 'Not applicable');
    if (!$categories) $categories = ['Digital guidance'];
    $flags = $currentMedications !== '' ? ['Current medications require reviewer conflict check'] : [];
    if (body_profile_token_from_assessment($assessment) !== '') $flags[] = 'Body-profile visual signals require reviewer validation';
    $reviewerNote = 'Medication, injectable and supplement dosages require licensed reviewer approval.';
    if ($currentMedications !== '') $reviewerNote .= ' Customer reported current medications: ' . $currentMedications;

    $stmt = db()->prepare('INSERT INTO member_plans (
        user_id,goal,medication,dosage,package_name,workout_plan,meal_plan,vitamins,weekly_targets,reviewer_note,
        status,focus,nutrition,activity,sleep,recovery,milestones,categories,product_ids,flags,next_check_in,version
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY),1)
    ON DUPLICATE KEY UPDATE
        goal=VALUES(goal),medication=VALUES(medication),dosage=VALUES(dosage),package_name=VALUES(package_name),
        workout_plan=VALUES(workout_plan),meal_plan=VALUES(meal_plan),vitamins=VALUES(vitamins),weekly_targets=VALUES(weekly_targets),
        reviewer_note=VALUES(reviewer_note),status=VALUES(status),focus=VALUES(focus),nutrition=VALUES(nutrition),activity=VALUES(activity),
        sleep=VALUES(sleep),recovery=VALUES(recovery),milestones=VALUES(milestones),categories=VALUES(categories),product_ids=VALUES(product_ids),
        flags=VALUES(flags),next_check_in=VALUES(next_check_in),version=version+1');
    $stmt->execute([
        $userId,
        $goal,
        $medication,
        $dosage,
        $packageName,
        json_encode($workout),
        json_encode($mealPlan),
        json_encode($vitamins),
        json_encode($targets),
        $reviewerNote,
        'needs_review',
        "A structured {$goal} plan based on the completed assessment and purchased products.",
        implode(' ', $mealPlan),
        implode(' ', $workout),
        'Target 7-8 hours with a consistent sleep and wake schedule.',
        'Use at least one lower-intensity recovery day each week.',
        json_encode(['Week 2: confirm routine adherence', 'Month 1: reviewer check-in', 'Month 3: reassess goals']),
        json_encode($categories),
        json_encode($productIds),
        json_encode($flags),
    ]);

    $assessmentWeight = (float)answer_value($assessment, 'weight', 0);
    if ($assessmentWeight >= 50 && $assessmentWeight <= 1000) {
        $existingWeight = db()->prepare('SELECT COUNT(*) FROM member_weight_logs WHERE user_id=?');
        $existingWeight->execute([$userId]);
        if ((int)$existingWeight->fetchColumn() === 0) {
            $weightInsert = db()->prepare('INSERT INTO member_weight_logs (user_id,weight_lbs) VALUES (?,?)');
            $weightInsert->execute([$userId, $assessmentWeight]);
        }
    }

    $planStmt = db()->prepare('SELECT * FROM member_plans WHERE user_id = ? LIMIT 1');
    $planStmt->execute([$userId]);
    return $planStmt->fetch() ?: [];
}


function member_plan_for_user(int $userId): ?array {
    $stmt = db()->prepare('SELECT * FROM member_plans WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function member_week_start(): string {
    $today = new DateTimeImmutable('today');
    return $today->modify('monday this week')->format('Y-m-d');
}

function sync_derived_weekly_targets(int $userId, array $plan): void {
    if ((string)($plan['status'] ?? '') !== 'released') return;
    $planId = (int)$plan['id'];
    if ($planId <= 0) return;
    $weekStart = member_week_start();
    $targets = array_values(array_filter(decode_json_array($plan['weekly_targets'] ?? null), static fn($item) => trim((string)$item) !== ''));
    if (!$targets) return;

    $weightStmt = db()->prepare('SELECT COUNT(*) FROM member_weight_logs WHERE user_id=? AND DATE(logged_at)>=?');
    $weightStmt->execute([$userId, $weekStart]);
    $hasWeight = (int)$weightStmt->fetchColumn() > 0;

    $checkinStmt = db()->prepare('SELECT COUNT(*) FROM member_checkins WHERE user_id=? AND DATE(created_at)>=?');
    $checkinStmt->execute([$userId, $weekStart]);
    $hasCheckin = (int)$checkinStmt->fetchColumn() > 0;

    $workouts = array_values(array_filter(decode_json_array($plan['workout_plan'] ?? null), static fn($item) => trim((string)$item) !== ''));
    $allWorkoutsDone = false;
    if ($workouts) {
        $completedStmt = db()->prepare("SELECT COUNT(*) FROM member_plan_progress WHERE user_id=? AND plan_id=? AND item_type='workout' AND period_start=? AND completed_at IS NOT NULL");
        $completedStmt->execute([$userId, $planId, $weekStart]);
        $allWorkoutsDone = (int)$completedStmt->fetchColumn() >= count($workouts);
    }

    foreach ($targets as $targetValue) {
        $target = trim((string)$targetValue);
        $lower = strtolower($target);
        $derived = null;
        if (str_contains($lower, 'weight')) $derived = $hasWeight;
        elseif (str_contains($lower, 'check-in') || str_contains($lower, 'check in')) $derived = $hasCheckin;
        elseif (str_contains($lower, 'workout') && (str_contains($lower, 'complete') || str_contains($lower, 'planned'))) $derived = $allWorkoutsDone;
        if ($derived === null) continue;
        $key = hash('sha256', $target);
        $stmt = db()->prepare("INSERT INTO member_plan_progress (user_id,plan_id,item_type,item_key,item_text,period_start,completed_at)
            VALUES (?,?, 'weekly_target',?,?,?,?)
            ON DUPLICATE KEY UPDATE item_text=VALUES(item_text),completed_at=VALUES(completed_at)");
        $stmt->execute([$userId, $planId, $key, $target, $weekStart, $derived ? date('Y-m-d H:i:s') : null]);
    }
}

function member_progress_payload(int $userId, ?array $plan): array {
    $weekStart = member_week_start();
    $weeklyTargets = [];
    $workouts = [];
    $planId = $plan ? (int)$plan['id'] : 0;
    if ($planId > 0 && $plan) sync_derived_weekly_targets($userId, $plan);
    $progressRows = [];
    if ($planId > 0) {
        $stmt = db()->prepare('SELECT * FROM member_plan_progress WHERE user_id=? AND plan_id=? AND period_start=?');
        $stmt->execute([$userId, $planId, $weekStart]);
        foreach ($stmt->fetchAll() as $row) {
            $progressRows[(string)$row['item_type'] . ':' . (string)$row['item_key']] = $row;
        }
    }

    $mapItems = static function (array $items, string $type) use ($progressRows): array {
        return array_values(array_map(static function ($item) use ($type, $progressRows): array {
            $text = trim((string)$item);
            $key = hash('sha256', $text);
            $row = $progressRows[$type . ':' . $key] ?? null;
            $lower = strtolower($text);
            $autoTracked = $type === 'weekly_target' && (
                str_contains($lower, 'weight') || str_contains($lower, 'check-in') || str_contains($lower, 'check in') ||
                (str_contains($lower, 'workout') && (str_contains($lower, 'complete') || str_contains($lower, 'planned')))
            );
            return [
                'item' => $text,
                'key' => $key,
                'completed' => $row ? !empty($row['completed_at']) : false,
                'completedAt' => $row['completed_at'] ?? null,
                'autoTracked' => $autoTracked,
            ];
        }, array_values(array_filter($items, static fn($item) => trim((string)$item) !== ''))));
    };

    if ($plan && (string)($plan['status'] ?? '') === 'released') {
        $weeklyTargets = $mapItems(decode_json_array($plan['weekly_targets'] ?? null), 'weekly_target');
        $workouts = $mapItems(decode_json_array($plan['workout_plan'] ?? null), 'workout');
    }

    $weightStmt = db()->prepare('SELECT id,weight_lbs,logged_at FROM member_weight_logs WHERE user_id=? ORDER BY logged_at DESC,id DESC LIMIT 180');
    $weightStmt->execute([$userId]);
    $weights = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'],
        'weightLbs' => (float)$row['weight_lbs'],
        'loggedAt' => (string)$row['logged_at'],
    ], $weightStmt->fetchAll());

    $checkinStmt = db()->prepare('SELECT id,energy_score,adherence_score,sleep_hours,note,created_at FROM member_checkins WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 180');
    $checkinStmt->execute([$userId]);
    $checkins = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'],
        'energyScore' => $row['energy_score'] !== null ? (int)$row['energy_score'] : null,
        'adherenceScore' => $row['adherence_score'] !== null ? (int)$row['adherence_score'] : null,
        'sleepHours' => $row['sleep_hours'] !== null ? (float)$row['sleep_hours'] : null,
        'note' => (string)($row['note'] ?? ''),
        'createdAt' => (string)$row['created_at'],
    ], $checkinStmt->fetchAll());

    $weeklyDone = count(array_filter($weeklyTargets, static fn(array $item): bool => !empty($item['completed'])));
    $workoutDone = count(array_filter($workouts, static fn(array $item): bool => !empty($item['completed'])));
    $checkinThisWeek = false;
    foreach ($checkins as $checkin) {
        if (substr((string)$checkin['createdAt'], 0, 10) >= $weekStart) { $checkinThisWeek = true; break; }
    }

    $nutritionStmt = db()->prepare('SELECT id,protein_grams,carbs_grams,hydration_oz,logged_on,created_at FROM member_nutrition_logs WHERE user_id=? ORDER BY logged_on DESC,id DESC LIMIT 180');
    $nutritionStmt->execute([$userId]);
    $nutritionRows = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'proteinGrams' => (float)$row['protein_grams'], 'carbsGrams' => (float)$row['carbs_grams'],
        'hydrationOz' => (float)$row['hydration_oz'], 'loggedOn' => (string)$row['logged_on'], 'createdAt' => (string)$row['created_at'],
    ], $nutritionStmt->fetchAll());
    $todayNutrition = null;
    foreach ($nutritionRows as $nutritionRow) { if ($nutritionRow['loggedOn'] === date('Y-m-d')) { $todayNutrition = $nutritionRow; break; } }

    $history = [];
    if ($planId > 0) {
        $historyStmt = db()->prepare('SELECT item_type,item_text,period_start,completed_at FROM member_plan_progress WHERE user_id=? AND plan_id=? AND period_start>=DATE_SUB(CURDATE(), INTERVAL 180 DAY) ORDER BY period_start ASC,id ASC');
        $historyStmt->execute([$userId, $planId]);
        $history = array_map(static fn(array $row): array => [
            'itemType' => (string)$row['item_type'],
            'item' => (string)$row['item_text'],
            'periodStart' => (string)$row['period_start'],
            'completedAt' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
        ], $historyStmt->fetchAll());
    }

    $totalActivityStmt = db()->prepare("SELECT
        (SELECT COUNT(*) FROM member_plan_progress WHERE user_id=? AND completed_at IS NOT NULL) +
        (SELECT COUNT(*) FROM member_checkins WHERE user_id=?) +
        (SELECT COUNT(*) FROM member_nutrition_logs WHERE user_id=?) +
        (SELECT COUNT(*) FROM member_weight_logs WHERE user_id=?)");
    $totalActivityStmt->execute([$userId,$userId,$userId,$userId]);
    $totalActivityCount = (int)$totalActivityStmt->fetchColumn();

    return [
        'weekStart' => $weekStart,
        'weeklyTargets' => $weeklyTargets,
        'workouts' => $workouts,
        'weeklyCompleted' => $weeklyDone,
        'weeklyTotal' => count($weeklyTargets),
        'workoutCompleted' => $workoutDone,
        'workoutTotal' => count($workouts),
        'recentWeights' => $weights,
        'latestWeight' => $weights[0] ?? null,
        'recentCheckins' => $checkins,
        'latestCheckin' => $checkins[0] ?? null,
        'checkinThisWeek' => $checkinThisWeek,
        'history' => $history,
        'totalActivityCount' => $totalActivityCount,
        'nutrition' => ['today' => $todayNutrition, 'recent' => $nutritionRows, 'targets' => ['proteinGrams' => 120, 'carbsGrams' => 180, 'hydrationOz' => 80]],
    ];
}

function require_released_plan_for_member(int $userId): array {
    $stmt = db()->prepare("SELECT * FROM member_plans WHERE user_id=? AND status='released' LIMIT 1");
    $stmt->execute([$userId]);
    $plan = $stmt->fetch();
    if (!$plan) respond(['message' => 'Your reviewer-published plan is required before tracking plan progress.'], 409);
    return $plan;
}

function active_admin_count(): int {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
}

function staff_reference_counts(int $userId): array {
    $counts = [
        'assignedOpenCases' => 0,
        'reviewerPlans' => 0,
        'reviewEvents' => 0,
        'bodyProfiles' => 0,
    ];
    if (database_table_exists('member_plans')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM member_plans WHERE reviewer_user_id=? AND status NOT IN ('released','rejected')");
        $stmt->execute([$userId]);
        $counts['assignedOpenCases'] = (int)$stmt->fetchColumn();
        $stmt = db()->prepare('SELECT COUNT(*) FROM member_plans WHERE reviewer_user_id=?');
        $stmt->execute([$userId]);
        $counts['reviewerPlans'] = (int)$stmt->fetchColumn();
    }
    if (database_table_exists('plan_review_events')) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM plan_review_events WHERE actor_user_id=?');
        $stmt->execute([$userId]);
        $counts['reviewEvents'] = (int)$stmt->fetchColumn();
    }
    if (database_table_exists('body_profiles') && database_column_exists('body_profiles', 'reviewed_by')) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM body_profiles WHERE reviewed_by=?');
        $stmt->execute([$userId]);
        $counts['bodyProfiles'] = (int)$stmt->fetchColumn();
    }
    $counts['reviewHistoryCount'] = $counts['reviewerPlans'] + $counts['reviewEvents'] + $counts['bodyProfiles'];
    $counts['canDelete'] = $counts['reviewHistoryCount'] === 0;
    return $counts;
}

function staff_user_payload(array $row): array {
    $payload = user_payload($row);
    $refs = staff_reference_counts((int)$row['id']);
    $payload['assignedOpenCases'] = $refs['assignedOpenCases'];
    $payload['reviewHistoryCount'] = $refs['reviewHistoryCount'];
    $payload['canDelete'] = $refs['canDelete'];
    return $payload;
}

function staff_audit_event(array $actor, array $target, string $action, ?string $fromRole = null, ?string $toRole = null, string $note = ''): void {
    if (!database_table_exists('staff_audit_events')) return;
    $stmt = db()->prepare('INSERT INTO staff_audit_events (actor_user_id,actor_name,target_user_id,target_name,target_email,action,from_role,to_role,note) VALUES (?,?,?,?,?,?,?,?,?)');
    $actorId = (int)($actor['id'] ?? 0);
    $targetId = (int)($target['id'] ?? 0);
    $stmt->execute([
        $actorId > 0 ? $actorId : null,
        staff_display_name($actor),
        $targetId > 0 ? $targetId : null,
        staff_display_name($target),
        (string)($target['email'] ?? ''),
        $action,
        $fromRole,
        $toRole,
        trim($note),
    ]);
}

function unassign_open_reviewer_cases(int $reviewerId, array $actor, string $reason): int {
    if (!database_table_exists('member_plans')) return 0;
    $stmt = db()->prepare("SELECT id,status FROM member_plans WHERE reviewer_user_id=? AND status NOT IN ('released','rejected')");
    $stmt->execute([$reviewerId]);
    $plans = $stmt->fetchAll();
    foreach ($plans as $plan) {
        $update = db()->prepare("UPDATE member_plans SET reviewer_user_id=NULL,reviewer='',reviewer_assigned_at=NULL,reviewer_approved_at=NULL,status='needs_review',version=version+1 WHERE id=?");
        $update->execute([(int)$plan['id']]);
        add_plan_review_event((int)$plan['id'], $actor, 'reviewer_unassigned_by_staff_change', (string)$plan['status'], 'needs_review', $reason);
    }
    return count($plans);
}

function staff_audit_payload(array $row): array {
    return [
        'id' => (string)$row['id'],
        'actorUserId' => !empty($row['actor_user_id']) ? (string)$row['actor_user_id'] : null,
        'actorName' => (string)$row['actor_name'],
        'targetUserId' => !empty($row['target_user_id']) ? (string)$row['target_user_id'] : null,
        'targetName' => (string)$row['target_name'],
        'targetEmail' => (string)$row['target_email'],
        'action' => (string)$row['action'],
        'fromRole' => $row['from_role'] !== null ? (string)$row['from_role'] : null,
        'toRole' => $row['to_role'] !== null ? (string)$row['to_role'] : null,
        'note' => (string)($row['note'] ?? ''),
        'createdAt' => (string)$row['created_at'],
    ];
}

try {
    if ($method === 'GET' && $path === '/cors-check') {
        $requestOrigin = normalize_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        respond([
            'ok' => true,
            'requestOrigin' => $requestOrigin,
            'originAllowed' => $requestOrigin !== '' ? cors_origin_is_allowed($requestOrigin) : null,
            'allowedOrigins' => cors_allowed_origins(),
            'method' => $method,
        ]);
    }

    ensure_runtime_schema();
    ensure_body_profile_schema();
    ensure_advisor_runtime_schema();

    if ($method === 'GET' && $path === '/health') {
        db()->query('SELECT 1');
        respond([
            'ok' => true,
            'service' => 'Thrivel IQ API',
            'time' => gmdate('c'),
            'llmEnabled' => openai_api_key() !== '' && function_exists('curl_init'),
            'llmKeyConfigured' => trim((string)(env_value('OPENAI_API_KEY', '') ?? '')) !== '',
            'curlAvailable' => function_exists('curl_init'),
            'advisorModel' => openai_model(),
            'advisorGuardModel' => openai_guard_model(),
            'advisorFallbackModel' => openai_fallback_model(),
            'advisorProviderRequestsPerNormalMessage' => 1,
            'bodyProfileEnabled' => true,
            'bodyProfileModel' => body_profile_openai_model(),
            'bodyProfileStorage' => 'private',
            'memberProgressEnabled' => true,
            'memberPasswordChangeEnabled' => true,
            'staffLifecycleEnabled' => true,
            'staffAuditEnabled' => true,
            'paymentMode' => env_value('PAYMENT_MODE', 'prototype'),
        ]);
    }

    if ($method === 'GET' && $path === '/auth/admin-setup/status') {
        respond([
            'setupRequired' => admin_setup_required(),
            'setupTokenConfigured' => admin_setup_token_configured(),
        ]);
    }

    if ($method === 'POST' && $path === '/auth/admin-setup') {
        if (!admin_setup_required()) respond(['message' => 'The first administrator has already been created.'], 409);
        if (!admin_setup_token_configured()) respond(['message' => 'ADMIN_SETUP_TOKEN is not configured in backend/.env.'], 503);

        $input = json_input();
        foreach (['firstName','lastName','email','password','setupToken'] as $key) {
            if (trim((string)($input[$key] ?? '')) === '') respond(['message' => "{$key} is required."], 422);
        }
        $expected = trim((string)(env_value('ADMIN_SETUP_TOKEN', '') ?? ''));
        $provided = trim((string)$input['setupToken']);
        if (!hash_equals($expected, $provided)) respond(['message' => 'The setup token is incorrect.'], 401);

        $email = strtolower(trim((string)$input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['message' => 'Enter a valid email address.'], 422);
        if (strlen((string)$input['password']) < 10) respond(['message' => 'Password must be at least 10 characters.'], 422);

        $pdo = db();
        $lock = (int)$pdo->query("SELECT GET_LOCK('thrivel_first_admin_setup', 10)")->fetchColumn();
        if ($lock !== 1) respond(['message' => 'Administrator setup is busy. Try again in a moment.'], 409);

        $payload = null;
        $failure = null;
        try {
            if (!admin_setup_required()) {
                $failure = [['message' => 'The first administrator has already been created.'], 409];
            } else {
                $existing = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                $existing->execute([$email]);
                if ($existing->fetch()) {
                    $failure = [['message' => 'An account with this email already exists.'], 409];
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,verified) VALUES (?,?,?,?, 'admin',1)");
                    $stmt->execute([
                        $email,
                        password_hash((string)$input['password'], PASSWORD_DEFAULT),
                        trim((string)$input['firstName']),
                        trim((string)$input['lastName']),
                    ]);
                    $userId = (int)$pdo->lastInsertId();
                    $session = issue_user_token($userId, true);
                    $pdo->commit();

                    $read = $pdo->prepare('SELECT * FROM users WHERE id=?');
                    $read->execute([$userId]);
                    $payload = ['user' => user_payload($read->fetch()), 'token' => $session['token'], 'expiresAt' => $session['expiresAt']];
                }
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->query("SELECT RELEASE_LOCK('thrivel_first_admin_setup')");
        }

        if ($failure !== null) respond($failure[0], $failure[1]);
        respond($payload ?? ['message' => 'Administrator setup failed.'], $payload ? 201 : 500);
    }

    if ($method === 'POST' && $path === '/auth/admin-login') {
        $input = json_input();
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if ($email === '' || $password === '') respond(['message' => 'Email and password are required.'], 422);
        $stmt = db()->prepare("SELECT * FROM users WHERE email=? AND role IN ('admin','reviewer') LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) respond(['message' => 'Incorrect staff email or password.'], 401);
        if (array_key_exists('is_active', $user) && !(bool)$user['is_active']) respond(['message' => 'This staff account is deactivated. Contact an administrator.'], 403);
        $session = issue_user_token((int)$user['id'], !array_key_exists('remember', $input) || !empty($input['remember']));
        $read = db()->prepare('SELECT * FROM users WHERE id=?');
        $read->execute([(int)$user['id']]);
        respond(['user' => user_payload($read->fetch()), 'token' => $session['token'], 'expiresAt' => $session['expiresAt']]);
    }

    if ($method === 'GET' && $path === '/admin/status') {
        $admin = require_admin();
        respond(['ok' => true, 'adminAuthorized' => true, 'user' => (int)($admin['id'] ?? 0) > 0 ? user_payload($admin) : null]);
    }

    if ($method === 'POST' && $path === '/admin/openai/test') {
        require_admin();
        if (openai_api_key() === '') respond(['ok' => false, 'message' => 'OPENAI_API_KEY is not configured in backend/.env.'], 503);
        if (!function_exists('curl_init')) respond(['ok' => false, 'message' => 'PHP cURL is not enabled for this domain. Enable the cURL extension first.'], 503);
        try {
            $model = openai_guard_model();
            $result = openai_response_request([
                'model' => $model,
                'reasoning' => ['effort' => 'none'],
                'instructions' => 'Return exactly OK and nothing else.',
                'input' => 'Connection test.',
                'max_output_tokens' => 32,
            ], 30);
            respond([
                'ok' => true,
                'model' => $model,
                'responseId' => (string)($result['id'] ?? ''),
                'output' => openai_output_text($result),
            ]);
        } catch (OpenAIProviderException $e) {
            respond([
                'ok' => false,
                'message' => $e->getMessage(),
                'providerStatus' => $e->providerStatus,
                'providerCode' => $e->providerCode,
                'model' => openai_guard_model(),
            ], 502);
        }
    }

    if ($method === 'GET' && $path === '/settings') {
        $row = db()->query('SELECT * FROM site_settings WHERE id = 1 LIMIT 1')->fetch();
        respond(['settings' => settings_payload($row ?: [])]);
    }

    if ($method === 'GET' && $path === '/admin/settings') {
        require_admin();
        $row = db()->query('SELECT * FROM site_settings WHERE id = 1 LIMIT 1')->fetch();
        respond(['settings' => settings_payload($row ?: [])]);
    }

    if ($method === 'PUT' && $path === '/admin/settings') {
        require_admin();
        $input = json_input();
        foreach (['primaryColor','gradientMidColor','secondaryColor','accentColor','backgroundColor','panelColor'] as $field) {
            if (isset($input[$field]) && !preg_match('/^#[0-9a-f]{6}$/i', (string)$input[$field])) {
                respond(['message' => "{$field} must be a six-digit hex color."], 422);
            }
        }

        $map = [
            'brand_name' => 'brandName', 'tagline' => 'tagline', 'logo_dark_url' => 'logoDarkUrl', 'logo_light_url' => 'logoLightUrl',
            'favicon_url' => 'faviconUrl', 'hero_image_url' => 'heroImageUrl', 'auth_image_url' => 'authImageUrl',
            'checkout_image_url' => 'checkoutImageUrl', 'dashboard_image_url' => 'dashboardImageUrl',
            'assessment_image_url' => 'assessmentImageUrl', 'default_product_image_url' => 'defaultProductImageUrl',
            'primary_color' => 'primaryColor', 'gradient_mid_color' => 'gradientMidColor', 'secondary_color' => 'secondaryColor',
            'accent_color' => 'accentColor', 'background_color' => 'backgroundColor', 'panel_color' => 'panelColor',
            'support_email' => 'supportEmail', 'footer_text' => 'footerText',
            'login_headline' => 'loginHeadline', 'login_subheadline' => 'loginSubheadline', 'login_title' => 'loginTitle',
            'login_description' => 'loginDescription', 'signup_headline' => 'signupHeadline', 'signup_subheadline' => 'signupSubheadline',
            'signup_title' => 'signupTitle', 'signup_description' => 'signupDescription', 'account_title' => 'accountTitle',
            'account_description' => 'accountDescription', 'checkout_title' => 'checkoutTitle', 'checkout_description' => 'checkoutDescription',
            'dashboard_title' => 'dashboardTitle', 'dashboard_description' => 'dashboardDescription',
        ];
        $columns = array_keys($map);
        $values = [];
        foreach ($map as $dbColumn => $apiField) $values[] = trim((string)($input[$apiField] ?? ''));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $updates = implode(',', array_map(fn(string $column): string => "{$column}=VALUES({$column})", $columns));
        $sql = 'INSERT INTO site_settings (id,' . implode(',', $columns) . ") VALUES (1,{$placeholders}) ON DUPLICATE KEY UPDATE {$updates}";
        $stmt = db()->prepare($sql);
        $stmt->execute($values);
        $row = db()->query('SELECT * FROM site_settings WHERE id = 1 LIMIT 1')->fetch();
        respond(['settings' => settings_payload($row ?: [])]);
    }

    if ($method === 'GET' && $path === '/admin/media') {
        require_admin();
        $rows = db()->query('SELECT * FROM media ORDER BY id DESC')->fetchAll();
        respond(['media' => array_map('media_payload', $rows)]);
    }

    if ($method === 'POST' && $path === '/admin/media') {
        require_admin();
        if (!isset($_FILES['file'])) respond(['message' => 'Choose an image to upload.'], 422);
        [$mime, $extension, $size] = safe_uploaded_image($_FILES['file']);
        $year = gmdate('Y');
        $month = gmdate('m');
        $relativeDir = "/uploads/{$year}/{$month}";
        $targetDir = __DIR__ . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            respond(['message' => 'Upload folder could not be created.'], 500);
        }
        $name = bin2hex(random_bytes(12)) . '.' . $extension;
        $relativeUrl = $relativeDir . '/' . $name;
        if (!move_uploaded_file((string)$_FILES['file']['tmp_name'], $targetDir . '/' . $name)) {
            respond(['message' => 'Uploaded image could not be saved.'], 500);
        }
        $original = basename((string)($_FILES['file']['name'] ?? $name));
        $alt = trim((string)($_POST['altText'] ?? pathinfo($original, PATHINFO_FILENAME)));
        $stmt = db()->prepare('INSERT INTO media (file_name,original_name,mime_type,url,alt_text,size_bytes) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$name, $original, $mime, $relativeUrl, $alt, $size]);
        $rowStmt = db()->prepare('SELECT * FROM media WHERE id = ?');
        $rowStmt->execute([db()->lastInsertId()]);
        respond(['media' => media_payload($rowStmt->fetch())], 201);
    }

    if (preg_match('#^/admin/media/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        require_admin();
        $stmt = db()->prepare('SELECT * FROM media WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$matches[1]]);
        $row = $stmt->fetch();
        if (!$row) respond(['message' => 'Media item not found.'], 404);
        if (str_contains((string)$row['url'], '/uploads/defaults/')) {
            respond(['message' => 'Bundled default images cannot be deleted. Replace them in Branding instead.'], 422);
        }
        $relative = parse_url((string)$row['url'], PHP_URL_PATH) ?: '';
        $filePath = __DIR__ . $relative;
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $fileParent = realpath(dirname($filePath));
        if ($uploadsRoot && $fileParent && str_starts_with($fileParent, $uploadsRoot) && is_file($filePath)) @unlink($filePath);
        $delete = db()->prepare('DELETE FROM media WHERE id = ?');
        $delete->execute([(int)$matches[1]]);
        respond(['deleted' => true]);
    }



    if ($method === 'POST' && $path === '/assessment/body-profile/session') {
        body_profile_cleanup_orphans();
        $input = json_input();
        if (empty($input['consent'])) respond(['message' => 'Consent is required before body photos can be uploaded.'], 422);
        $token = body_profile_token();
        $stmt = db()->prepare("INSERT INTO body_profiles (assessment_token,status,consent_at) VALUES (?,'created',NOW())");
        $stmt->execute([$token]);
        $row = body_profile_row($token);
        respond(['bodyProfile' => body_profile_payload($row ?: [])], 201);
    }

    if (preg_match('#^/assessment/body-profile/([a-f0-9]{64})/upload$#', $path, $matches) && $method === 'POST') {
        $token = $matches[1];
        $row = body_profile_row($token);
        if (!$row || (string)$row['status'] === 'deleted') respond(['message' => 'Body-profile session not found.'], 404);
        if (!isset($_FILES['file'])) respond(['message' => 'Choose a body photo to upload.'], 422);
        $view = strtolower(trim((string)($_POST['view'] ?? '')));
        if (!in_array($view, ['front','side','back'], true)) respond(['message' => 'Photo view must be front, side, or back.'], 422);
        [$mime, $extension] = body_profile_uploaded_image($_FILES['file']);
        $dir = body_profile_storage_root() . '/' . $token;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Could not create body-profile upload directory.');
        $filename = $view . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file((string)$_FILES['file']['tmp_name'], $target)) throw new RuntimeException('Could not store the body photo.');
        @chmod($target, 0640);
        $column = $view . '_path';
        $old = trim((string)($row[$column] ?? ''));
        if ($old !== '' && is_file($old)) @unlink($old);
        $stmt = db()->prepare("UPDATE body_profiles SET {$column}=?,status='uploaded',visual_summary=NULL,visible_signals=NULL,goal_tags=NULL,analysis_json=NULL,confidence='low',ai_model=NULL WHERE assessment_token=?");
        $stmt->execute([$target, $token]);
        respond(['bodyProfile' => body_profile_payload(body_profile_row($token) ?: $row)]);
    }

    if (preg_match('#^/assessment/body-profile/([a-f0-9]{64})/analyze$#', $path, $matches) && $method === 'POST') {
        $token = $matches[1];
        $row = body_profile_row($token);
        if (!$row || (string)$row['status'] === 'deleted') respond(['message' => 'Body-profile session not found.'], 404);
        $input = json_input();
        $assessment = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $result = body_profile_analyze($row, $assessment);
        $analysis = $result['analysis'];
        $stmt = db()->prepare("UPDATE body_profiles SET visual_summary=?,visible_signals=?,goal_tags=?,analysis_json=?,confidence=?,ai_model=?,status='review_pending' WHERE assessment_token=?");
        $stmt->execute([
            (string)$analysis['visualSummary'],
            json_encode($analysis['visibleSignals'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($analysis['goalTags'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string)$analysis['confidence'],
            (string)$result['model'],
            $token,
        ]);
        respond(['bodyProfile' => body_profile_payload(body_profile_row($token) ?: $row)]);
    }

    if (preg_match('#^/assessment/body-profile/([a-f0-9]{64})$#', $path, $matches) && $method === 'DELETE') {
        $row = body_profile_row($matches[1]);
        if (!$row) respond(['deleted' => true]);
        if (!empty($row['user_id'])) respond(['message' => 'Log in to delete body photos linked to your account.'], 403);
        body_profile_delete_files($row);
        $stmt = db()->prepare("UPDATE body_profiles SET front_path=NULL,side_path=NULL,back_path=NULL,status='deleted',analysis_json=NULL,goal_tags=NULL,visible_signals=NULL,visual_summary=NULL WHERE id=?");
        $stmt->execute([(int)$row['id']]);
        respond(['deleted' => true]);
    }

    if ($method === 'POST' && $path === '/recommendations/match') {
        $input = json_input();
        $assessment = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $intents = build_match_intents($assessment);
        $rows = db()->query("SELECT * FROM products WHERE active = 1 AND product_type IN ('research','stack') ORDER BY sort_order ASC, name ASC")->fetchAll();
        $ranked = [];
        foreach ($rows as $row) {
            $match = product_match_score($row, $intents);
            if ($match['score'] > 0) $ranked[] = ['row' => $row, 'score' => $match['score'], 'reasons' => $match['reasons']];
        }
        usort($ranked, static function (array $a, array $b): int {
            $scoreOrder = $b['score'] <=> $a['score'];
            if ($scoreOrder !== 0) return $scoreOrder;
            return ((int)$a['row']['sort_order']) <=> ((int)$b['row']['sort_order']);
        });

        if (!$ranked) {
            $primary = (string)answer_value($assessment, 'primary_goal', '');
            $categoryByGoal = [
                'Weight management' => 'Weight Loss & Metabolic',
                'Recovery & healing' => 'Recovery & Healing',
                'Longevity' => 'Anti-Aging & Longevity',
                'Performance' => 'Growth Hormone & Body Composition',
                'Energy' => 'Anti-Aging & Longevity',
            ];
            $fallbackCategory = $categoryByGoal[$primary] ?? '';
            foreach ($rows as $row) {
                if ($fallbackCategory !== '' && $row['category'] !== $fallbackCategory) continue;
                $useCases = decode_json_array($row['use_cases'] ?? null);
                $goalTags = decode_json_array($row['goal_tags'] ?? null);
                $ranked[] = ['row' => $row, 'score' => 1.0, 'reasons' => array_slice($useCases ?: $goalTags, 0, 2)];
                if (count($ranked) >= 3) break;
            }
        }

        $ranked = array_slice($ranked, 0, 4);
        respond([
            'matches' => array_map(static fn(array $item): array => [
                'productId' => (string)$item['row']['slug'],
                'score' => round((float)$item['score'], 2),
                'reasons' => array_values($item['reasons']),
            ], $ranked),
            'products' => array_map(static fn(array $item): array => product_payload($item['row']), $ranked),
        ]);
    }

    if ($method === 'GET' && $path === '/products') {
        $stmt = db()->query('SELECT * FROM products WHERE active = 1 ORDER BY sort_order ASC, name ASC');
        respond(['products' => array_map('product_payload', $stmt->fetchAll())]);
    }

    if ($method === 'GET' && $path === '/admin/products') {
        require_admin();
        $stmt = db()->query('SELECT * FROM products ORDER BY sort_order ASC, name ASC');
        respond(['products' => array_map('product_payload', $stmt->fetchAll())]);
    }

    if ($method === 'POST' && $path === '/admin/products') {
        require_admin();
        $input = json_input();
        foreach (['id','name','category','size'] as $key) {
            if (trim((string)($input[$key] ?? '')) === '') respond(['message' => "{$key} is required."], 422);
        }
        $slug = strtolower(trim((string)$input['id']));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) respond(['message' => 'Product ID must use lowercase letters, numbers, and hyphens.'], 422);
        $stmt = db()->prepare('INSERT INTO products (slug,name,category,size_label,price,standalone_price,annual_price,billing_interval,description,compound,usage_notice,goal_tags,use_cases,tags,product_type,active,medication,dosage,image_url,image_alt,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $slug, trim((string)$input['name']), trim((string)$input['category']), trim((string)$input['size']),
            (float)($input['price'] ?? 0), ($input['standalonePrice'] ?? '') === '' ? null : (float)$input['standalonePrice'], annual_billing_price((float)($input['price'] ?? 0)),
            'month',
            trim((string)($input['description'] ?? '')), trim((string)($input['compound'] ?? '')), trim((string)($input['usageNotice'] ?? '')),
            json_encode(array_values($input['goalTags'] ?? [])), json_encode(array_values($input['useCases'] ?? [])), json_encode(array_values($input['tags'] ?? [])), (string)($input['productType'] ?? 'research'), !empty($input['active']) ? 1 : 0,
            trim((string)($input['medication'] ?? '')), trim((string)($input['dosage'] ?? '')), trim((string)($input['imageUrl'] ?? '')),
            trim((string)($input['imageAlt'] ?? '')), (int)($input['sortOrder'] ?? 100),
        ]);
        $row = db()->prepare('SELECT * FROM products WHERE slug = ?');
        $row->execute([$slug]);
        respond(['product' => product_payload($row->fetch())], 201);
    }

    if (preg_match('#^/admin/products/([a-z0-9-]+)$#', $path, $matches)) {
        require_admin();
        $slug = $matches[1];
        if ($method === 'PUT') {
            $input = json_input();
            $stmt = db()->prepare('UPDATE products SET name=?,category=?,size_label=?,price=?,standalone_price=?,annual_price=?,billing_interval=?,description=?,compound=?,usage_notice=?,goal_tags=?,use_cases=?,tags=?,product_type=?,active=?,medication=?,dosage=?,image_url=?,image_alt=?,sort_order=?,updated_at=NOW() WHERE slug=?');
            $stmt->execute([
                trim((string)($input['name'] ?? '')), trim((string)($input['category'] ?? '')), trim((string)($input['size'] ?? '')),
                (float)($input['price'] ?? 0), ($input['standalonePrice'] ?? '') === '' ? null : (float)$input['standalonePrice'], annual_billing_price((float)($input['price'] ?? 0)),
                'month',
                trim((string)($input['description'] ?? '')), trim((string)($input['compound'] ?? '')), trim((string)($input['usageNotice'] ?? '')),
                json_encode(array_values($input['goalTags'] ?? [])), json_encode(array_values($input['useCases'] ?? [])), json_encode(array_values($input['tags'] ?? [])), (string)($input['productType'] ?? 'research'), !empty($input['active']) ? 1 : 0,
                trim((string)($input['medication'] ?? '')), trim((string)($input['dosage'] ?? '')), trim((string)($input['imageUrl'] ?? '')),
                trim((string)($input['imageAlt'] ?? '')), (int)($input['sortOrder'] ?? 100), $slug,
            ]);
            $row = db()->prepare('SELECT * FROM products WHERE slug = ?');
            $row->execute([$slug]);
            $record = $row->fetch();
            if (!$record) respond(['message' => 'Product not found.'], 404);
            respond(['product' => product_payload($record)]);
        }
        if ($method === 'DELETE') {
            $stmt = db()->prepare('DELETE FROM products WHERE slug = ?');
            $stmt->execute([$slug]);
            respond(['deleted' => true]);
        }
    }

    if ($method === 'POST' && $path === '/checkout/orders') {
        $input = json_input();
        $advisorStmt = db()->prepare("SELECT * FROM products WHERE slug = ? AND product_type = 'service' AND active = 1 LIMIT 1");
        $advisorStmt->execute([(string)($input['advisorProductId'] ?? 'ai-health-advisor')]);
        $advisor = $advisorStmt->fetch();
        if (!$advisor) respond(['message' => 'AI Health Coach product is unavailable. Add or activate it in Admin Products.'], 422);

        $productIds = is_array($input['productIds'] ?? null) ? $input['productIds'] : [];
        if (!$productIds && !empty($input['stackProductId'])) $productIds = [(string)$input['stackProductId']];
        $productIds = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $productIds))));
        if (count($productIds) > 20) respond(['message' => 'A maximum of 20 products can be checked out at once.'], 422);
        foreach ($productIds as $productId) {
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $productId)) respond(['message' => 'One or more selected products are invalid.'], 422);
        }

        $selectedRows = [];
        if ($productIds) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $productStmt = db()->prepare("SELECT * FROM products WHERE active = 1 AND product_type <> 'service' AND slug IN ({$placeholders})");
            $productStmt->execute($productIds);
            $bySlug = [];
            foreach ($productStmt->fetchAll() as $row) $bySlug[(string)$row['slug']] = $row;
            foreach ($productIds as $productId) {
                if (!isset($bySlug[$productId])) respond(['message' => "Selected product {$productId} is unavailable."], 422);
                $selectedRows[] = $bySlug[$productId];
            }
        }

        $requestedProductCycles = is_array($input['productBillingCycles'] ?? null) ? $input['productBillingCycles'] : [];
        $productBillingCycles = [];
        $items = [];
        $productSubtotal = 0.0;
        foreach ($selectedRows as $row) {
            $slug = (string)$row['slug'];
            $cycle = (($requestedProductCycles[$slug] ?? 'month') === 'year') ? 'year' : 'month';
            $price = $cycle === 'year' ? annual_billing_price((float)$row['price']) : (float)$row['price'];
            $productBillingCycles[$slug] = $cycle;
            $productSubtotal += $price;
            $items[] = [
                'id' => $slug,
                'name' => (string)$row['name'],
                'category' => (string)$row['category'],
                'size' => (string)$row['size_label'],
                'price' => $price,
                'billingCycle' => $cycle,
                'imageUrl' => (string)($row['image_url'] ?? ''),
            ];
        }
        $advisorBillingCycle = in_array((string)($input['advisorBillingCycle'] ?? 'month'), ['month','year'], true) ? (string)$input['advisorBillingCycle'] : 'month';
        $advisorMonthlyPrice = (float)($advisor['price'] ?? 19.99);
        $advisorAnnualPrice = annual_billing_price($advisorMonthlyPrice);
        $advisorPrice = count($selectedRows) > 0 ? 0.00 : ($advisorBillingCycle === 'year' ? $advisorAnnualPrice : $advisorMonthlyPrice);
        $firstProduct = $selectedRows[0] ?? null;
        $token = random_token(24);
        $paymentMode = env_value('PAYMENT_MODE', 'prototype');
        $paymentStatus = $paymentMode === 'prototype' ? 'paid' : 'pending';
        $guest = is_array($input['guest'] ?? null) ? $input['guest'] : [];
        $shippingAddress = is_array($input['shippingAddress'] ?? null) ? $input['shippingAddress'] : [];
        $checkoutUser = find_user_by_bearer_token();
        if ($checkoutUser && (string)($checkoutUser['role'] ?? 'customer') === 'customer') {
            $guest['email'] = (string)$checkoutUser['email'];
            $guest['firstName'] = (string)$checkoutUser['first_name'];
            $guest['lastName'] = (string)$checkoutUser['last_name'];
            $latestMemberOrder = latest_order_for_user((int)$checkoutUser['id']);
            $savedShipping = $latestMemberOrder ? json_decode((string)($latestMemberOrder['shipping_address_json'] ?? '{}'), true) : [];
            if (!is_array($savedShipping)) $savedShipping = [];
            foreach (['address1','address2','city','state','postalCode','country','phone'] as $shippingKey) {
                if (trim((string)($shippingAddress[$shippingKey] ?? '')) === '' && trim((string)($savedShipping[$shippingKey] ?? '')) !== '') {
                    $shippingAddress[$shippingKey] = $savedShipping[$shippingKey];
                }
            }
            if (trim((string)($shippingAddress['state'] ?? '')) === '') $shippingAddress['state'] = (string)($checkoutUser['state'] ?? '');
            if (trim((string)($shippingAddress['country'] ?? '')) === '') $shippingAddress['country'] = (string)($checkoutUser['country'] ?? '');
            if (trim((string)($shippingAddress['phone'] ?? '')) === '') $shippingAddress['phone'] = (string)($checkoutUser['phone'] ?? '');
        }
        $email = strtolower(trim((string)($guest['email'] ?? '')));
        $firstName = trim((string)($guest['firstName'] ?? ''));
        $lastName = trim((string)($guest['lastName'] ?? ''));
        foreach (['address1','city','state','postalCode','country'] as $shippingKey) { if (trim((string)($shippingAddress[$shippingKey] ?? '')) === '') respond(['message' => 'Complete shipping address is required.'], 422); }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['message' => 'A valid checkout email is required.'], 422);

        $stmt = db()->prepare('INSERT INTO orders (order_token,email,first_name,last_name,stack_product_id,stack_name,stack_price,product_subtotal,advisor_price,total,payment_status,payment_reference,assessment_json,items_json,shipping_address_json,advisor_billing_cycle,product_billing_cycles_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $token, $email, $firstName, $lastName, $firstProduct['id'] ?? null, $firstProduct['name'] ?? null, $productSubtotal, $productSubtotal, $advisorPrice,
            $productSubtotal + $advisorPrice, $paymentStatus, trim((string)($input['paymentReference'] ?? '')), json_encode($input['answers'] ?? []), json_encode($items), json_encode($shippingAddress), $advisorBillingCycle, json_encode($productBillingCycles),
        ]);
        $orderId = (int)db()->lastInsertId();
        body_profile_bind_order(is_array($input['answers'] ?? null) ? $input['answers'] : [], $orderId);
        if ($checkoutUser && (string)($checkoutUser['role'] ?? 'customer') === 'customer') {
            db()->prepare('UPDATE orders SET user_id=?,account_created_at=COALESCE(account_created_at,NOW()) WHERE id=?')->execute([(int)$checkoutUser['id'], $orderId]);
        }
        $orderStmt = db()->prepare('SELECT o.*, p.slug AS stack_slug FROM orders o LEFT JOIN products p ON p.id = o.stack_product_id WHERE o.id = ?');
        $orderStmt->execute([$orderId]);
        $createdOrder = $orderStmt->fetch();
        if ($checkoutUser && $createdOrder && (string)($createdOrder['payment_status'] ?? '') === 'paid') {
            ensure_product_subscriptions_for_order((int)$checkoutUser['id'], $createdOrder);
            ensure_advisor_subscription_for_user((int)$checkoutUser['id'], $createdOrder);
        }
        respond(['order' => order_payload($createdOrder)], 201);
    }

    if (preg_match('#^/checkout/orders/([a-f0-9]+)$#', $path, $matches) && $method === 'GET') {
        $stmt = db()->prepare('SELECT o.*, p.slug AS stack_slug FROM orders o LEFT JOIN products p ON p.id = o.stack_product_id WHERE o.order_token = ? LIMIT 1');
        $stmt->execute([$matches[1]]);
        $order = $stmt->fetch();
        if (!$order) respond(['message' => 'Order not found.'], 404);
        respond(['order' => order_payload($order)]);
    }

    if ($method === 'POST' && $path === '/auth/register-after-payment') {
        $input = json_input();
        foreach (['password','orderToken'] as $key) {
            if (trim((string)($input[$key] ?? '')) === '') respond(['message' => "{$key} is required."], 422);
        }
        if (strlen((string)$input['password']) < 8) respond(['message' => 'Password must be at least 8 characters.'], 422);

        $orderStmt = db()->prepare("SELECT * FROM orders WHERE order_token = ? AND payment_status = 'paid' LIMIT 1");
        $orderStmt->execute([(string)$input['orderToken']]);
        $order = $orderStmt->fetch();
        if (!$order) respond(['message' => 'Paid order not found.'], 403);
        if (!empty($order['user_id'])) respond(['message' => 'This order already has an account. Log in instead.'], 409);
        $email = strtolower(trim((string)($order['email'] ?? '')));
        $firstName = trim((string)($order['first_name'] ?? ''));
        $lastName = trim((string)($order['last_name'] ?? ''));
        $shipping = json_decode((string)($order['shipping_address_json'] ?? '{}'), true) ?: [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['message' => 'The paid order is missing a valid email address.'], 422);

        $existing = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $existing->execute([$email]);
        if ($existing->fetch()) respond(['message' => 'An account with this email already exists. Log in instead.'], 409);

        db()->beginTransaction();
        $userStmt = db()->prepare('INSERT INTO users (email,password_hash,first_name,last_name,country,state,phone,verified) VALUES (?,?,?,?,?,?,?,1)');
        $userStmt->execute([
            $email, password_hash((string)$input['password'], PASSWORD_DEFAULT), $firstName, $lastName,
            trim((string)($shipping['country'] ?? '')), trim((string)($shipping['state'] ?? '')), trim((string)($shipping['phone'] ?? '')),
        ]);
        $userId = (int)db()->lastInsertId();
        $session = issue_user_token($userId, true);
        $update = db()->prepare('UPDATE orders SET user_id=?,email=?,first_name=?,last_name=?,account_created_at=NOW() WHERE id=?');
        $update->execute([$userId, $email, $firstName, $lastName, (int)$order['id']]);
        body_profile_bind_user_from_order($order, $userId);
        $order['user_id'] = $userId;
        $order['email'] = $email;
        $order['first_name'] = $firstName;
        $order['last_name'] = $lastName;
        $plan = create_member_plan_for_order($userId, $order);
        ensure_product_subscriptions_for_order($userId, $order);
        $advisorSubscription = ensure_advisor_subscription_for_user($userId, $order);
        db()->commit();

        $userRead = db()->prepare('SELECT * FROM users WHERE id = ?');
        $userRead->execute([$userId]);
        respond([
            'user' => user_payload($userRead->fetch()),
            'token' => $session['token'],
            'expiresAt' => $session['expiresAt'],
            'plan' => member_plan_payload($plan),
            'subscription' => $advisorSubscription ? advisor_subscription_payload($advisorSubscription) : null,
        ], 201);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $input = json_input();
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if ($email === '' || $password === '') respond(['message' => 'Email and password are required.'], 422);
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) respond(['message' => 'Incorrect email or password.'], 401);
        if (array_key_exists('is_active', $user) && !(bool)$user['is_active']) respond(['message' => 'This account is deactivated. Contact support.'], 403);
        $session = issue_user_token((int)$user['id'], !array_key_exists('remember', $input) || !empty($input['remember']));
        $read = db()->prepare('SELECT * FROM users WHERE id=?');
        $read->execute([(int)$user['id']]);
        respond(['user' => user_payload($read->fetch()), 'token' => $session['token'], 'expiresAt' => $session['expiresAt']]);
    }

    if ($method === 'GET' && $path === '/auth/me') {
        $user = require_user();
        respond(['user' => user_payload($user)]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        $user = require_user();
        $stmt = db()->prepare('UPDATE users SET api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $stmt->execute([(int)$user['id']]);
        respond(['loggedOut' => true]);
    }

    if ($method === 'POST' && $path === '/auth/change-password') {
        $user = require_user();
        $input = json_input();
        $currentPassword = (string)($input['currentPassword'] ?? '');
        $newPassword = (string)($input['newPassword'] ?? '');
        if ($currentPassword === '' || $newPassword === '') respond(['message' => 'Current and new passwords are required.'], 422);
        if (!password_verify($currentPassword, (string)$user['password_hash'])) respond(['message' => 'Current password is incorrect.'], 401);
        if (strlen($newPassword) < 10) respond(['message' => 'New password must be at least 10 characters.'], 422);
        if (password_verify($newPassword, (string)$user['password_hash'])) respond(['message' => 'Choose a password different from your current password.'], 422);
        $stmt = db()->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
        respond(['changed' => true]);
    }

    if ($method === 'GET' && $path === '/me/dashboard') {
        $user = require_user();
        $planStmt = db()->prepare('SELECT * FROM member_plans WHERE user_id = ? LIMIT 1');
        $planStmt->execute([(int)$user['id']]);
        $plan = $planStmt->fetch();
        $order = latest_order_for_user((int)$user['id']);
        $subscription = ensure_advisor_subscription_for_user((int)$user['id'], $order);
        respond([
            'user' => user_payload($user),
            'plan' => $plan ? member_plan_payload($plan) : null,
            'order' => $order ? order_payload($order) : null,
            'subscription' => $subscription ? advisor_subscription_payload($subscription) : null,
            'progress' => member_progress_payload((int)$user['id'], $plan ?: null),
        ]);
    }

    if ($method === 'POST' && $path === '/me/progress/toggle') {
        $user = require_user();
        $input = json_input();
        $itemType = trim((string)($input['itemType'] ?? ''));
        $itemText = trim((string)($input['item'] ?? ''));
        $completed = !empty($input['completed']);
        if (!in_array($itemType, ['weekly_target','workout'], true)) respond(['message' => 'Invalid progress item type.'], 422);
        if ($itemText === '') respond(['message' => 'Progress item is required.'], 422);
        $plan = require_released_plan_for_member((int)$user['id']);
        $source = $itemType === 'weekly_target' ? decode_json_array($plan['weekly_targets'] ?? null) : decode_json_array($plan['workout_plan'] ?? null);
        $allowed = array_map(static fn($item): string => trim((string)$item), $source);
        if (!in_array($itemText, $allowed, true)) respond(['message' => 'This item is not part of your current published plan.'], 409);
        $weekStart = member_week_start();
        $itemKey = hash('sha256', $itemText);
        $stmt = db()->prepare("INSERT INTO member_plan_progress (user_id,plan_id,item_type,item_key,item_text,period_start,completed_at)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE item_text=VALUES(item_text),completed_at=VALUES(completed_at)");
        $stmt->execute([(int)$user['id'], (int)$plan['id'], $itemType, $itemKey, $itemText, $weekStart, $completed ? date('Y-m-d H:i:s') : null]);
        respond(['progress' => member_progress_payload((int)$user['id'], $plan)]);
    }

    if ($method === 'POST' && $path === '/me/weight-log') {
        $user = require_user();
        $input = json_input();
        $weight = (float)($input['weightLbs'] ?? 0);
        if ($weight < 50 || $weight > 1000) respond(['message' => 'Enter a weight between 50 and 1000 lb.'], 422);
        $stmt = db()->prepare('INSERT INTO member_weight_logs (user_id,weight_lbs) VALUES (?,?)');
        $stmt->execute([(int)$user['id'], $weight]);
        $planStmt = db()->prepare('SELECT * FROM member_plans WHERE user_id=? LIMIT 1');
        $planStmt->execute([(int)$user['id']]);
        $plan = $planStmt->fetch() ?: null;
        respond(['progress' => member_progress_payload((int)$user['id'], $plan)]);
    }

    if ($method === 'POST' && $path === '/me/checkins') {
        $user = require_user();
        $input = json_input();
        $energy = isset($input['energyScore']) ? (int)$input['energyScore'] : null;
        $adherence = isset($input['adherenceScore']) ? (int)$input['adherenceScore'] : null;
        $sleepHours = isset($input['sleepHours']) && $input['sleepHours'] !== '' ? (float)$input['sleepHours'] : null;
        $note = trim((string)($input['note'] ?? ''));
        if ($energy !== null && ($energy < 1 || $energy > 10)) respond(['message' => 'Energy score must be between 1 and 10.'], 422);
        if ($adherence !== null && ($adherence < 1 || $adherence > 10)) respond(['message' => 'Plan adherence must be between 1 and 10.'], 422);
        if ($sleepHours !== null && ($sleepHours < 0 || $sleepHours > 24)) respond(['message' => 'Sleep hours must be between 0 and 24.'], 422);
        if ($energy === null && $adherence === null && $sleepHours === null && $note === '') respond(['message' => 'Add at least one check-in value.'], 422);
        $planStmt = db()->prepare('SELECT * FROM member_plans WHERE user_id=? LIMIT 1');
        $planStmt->execute([(int)$user['id']]);
        $plan = $planStmt->fetch() ?: null;
        $stmt = db()->prepare('INSERT INTO member_checkins (user_id,plan_id,energy_score,adherence_score,sleep_hours,note) VALUES (?,?,?,?,?,?)');
        $stmt->execute([(int)$user['id'], $plan ? (int)$plan['id'] : null, $energy, $adherence, $sleepHours, $note]);
        respond(['progress' => member_progress_payload((int)$user['id'], $plan)]);
    }

    if ($method === 'POST' && $path === '/me/reviewer-response') {
        $user = require_user();
        $input = json_input();
        $response = trim((string)($input['response'] ?? ''));
        if ($response === '') respond(['message' => 'Enter the information requested by your reviewer.'], 422);
        if (strlen($response) > 5000) respond(['message' => 'Response is too long.'], 422);
        $planStmt = db()->prepare('SELECT * FROM member_plans WHERE user_id=? LIMIT 1');
        $planStmt->execute([(int)$user['id']]);
        $plan = $planStmt->fetch();
        if (!$plan) respond(['message' => 'No member plan was found.'], 404);
        if ((string)$plan['status'] !== 'needs_information') respond(['message' => 'Your reviewer is not currently requesting additional information.'], 409);
        $stmt = db()->prepare("UPDATE member_plans SET member_response=?,member_response_at=NOW(),status='in_review' WHERE id=?");
        $stmt->execute([$response, (int)$plan['id']]);
        add_plan_review_event((int)$plan['id'], $user, 'member_submitted_information', (string)$plan['status'], 'in_review', $response);
        $fresh = db()->prepare('SELECT * FROM member_plans WHERE id=?');
        $fresh->execute([(int)$plan['id']]);
        respond(['plan' => member_plan_payload($fresh->fetch())]);
    }


    if ($method === 'GET' && $path === '/me/body-profile') {
        $user = require_user();
        $row = body_profile_for_user((int)$user['id']);
        respond(['bodyProfile' => $row ? body_profile_payload($row) : null]);
    }

    if ($method === 'DELETE' && $path === '/me/body-profile') {
        $user = require_user();
        $row = body_profile_for_user((int)$user['id']);
        if (!$row) respond(['deleted' => true]);
        body_profile_delete_files($row);
        $stmt = db()->prepare("UPDATE body_profiles SET front_path=NULL,side_path=NULL,back_path=NULL,status='deleted',analysis_json=NULL,goal_tags=NULL,visible_signals=NULL,visual_summary=NULL WHERE id=? AND user_id=?");
        $stmt->execute([(int)$row['id'], (int)$user['id']]);
        respond(['deleted' => true]);
    }

    if ($method === 'GET' && $path === '/me/profile') {
        $user = require_user();
        respond(['user' => user_payload($user)]);
    }

    if ($method === 'PUT' && $path === '/me/profile') {
        $user = require_user();
        $input = json_input();
        $firstName = trim((string)($input['firstName'] ?? $user['first_name']));
        $lastName = trim((string)($input['lastName'] ?? $user['last_name']));
        $email = strtolower(trim((string)($input['email'] ?? $user['email'])));
        if ($firstName === '' || $lastName === '') respond(['message' => 'First and last name are required.'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['message' => 'Enter a valid email address.'], 422);
        $emailCheck = db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
        $emailCheck->execute([$email, (int)$user['id']]);
        if ($emailCheck->fetch()) respond(['message' => 'That email address is already used by another account.'], 409);
        $notifications = is_array($input['notifications'] ?? null) ? $input['notifications'] : [];
        $stmt = db()->prepare('UPDATE users SET first_name=?,last_name=?,email=?,phone=?,country=?,state=?,plan_updates=?,reviewer_messages=?,marketing_emails=? WHERE id=?');
        $stmt->execute([
            $firstName, $lastName, $email, trim((string)($input['phone'] ?? '')), trim((string)($input['country'] ?? '')), trim((string)($input['state'] ?? '')),
            !empty($notifications['planUpdates']) ? 1 : 0, !empty($notifications['reviewerMessages']) ? 1 : 0,
            !empty($notifications['marketingEmails']) ? 1 : 0, (int)$user['id'],
        ]);
        $read = db()->prepare('SELECT * FROM users WHERE id = ?');
        $read->execute([(int)$user['id']]);
        respond(['user' => user_payload($read->fetch())]);
    }

    if ($method === 'GET' && $path === '/admin/members') {
        require_admin();
        $rows = db()->query("SELECT u.*,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) AS order_count,
            (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.user_id=u.id AND o.payment_status='paid') AS lifetime_value,
            (SELECT o.shipping_address_json FROM orders o WHERE o.user_id=u.id ORDER BY o.id DESC LIMIT 1) AS latest_shipping,
            (SELECT o.created_at FROM orders o WHERE o.user_id=u.id ORDER BY o.id DESC LIMIT 1) AS latest_order_at,
            (SELECT mp.status FROM member_plans mp WHERE mp.user_id=u.id ORDER BY mp.id DESC LIMIT 1) AS plan_status
            FROM users u WHERE u.role='customer' ORDER BY u.created_at DESC")->fetchAll();
        $members = array_map(static function(array $row): array {
            $base = user_payload($row);
            $shipping = json_decode((string)($row['latest_shipping'] ?? '{}'), true);
            $base['orderCount'] = (int)($row['order_count'] ?? 0);
            $base['lifetimeValue'] = (float)($row['lifetime_value'] ?? 0);
            $base['shippingAddress'] = is_array($shipping) ? $shipping : null;
            $base['latestOrderAt'] = $row['latest_order_at'] ?? null;
            $base['planStatus'] = $row['plan_status'] ?? null;
            return $base;
        }, $rows);
        respond(['members' => $members]);
    }


    if (preg_match('#^/admin/members/(\d+)/deactivate$#', $path, $matches) && $method === 'POST') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role='customer' LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Member not found.'], 404);
        if (!(bool)($target['is_active'] ?? true)) respond(['user' => user_payload($target)]);
        $update = db()->prepare('UPDATE users SET is_active=0,deactivated_at=NOW(),deactivated_by=?,api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $actorId = (int)($admin['id'] ?? 0);
        $update->execute([$actorId > 0 ? $actorId : null, $targetId]);
        $read->execute([$targetId]);
        respond(['user' => user_payload($read->fetch())]);
    }

    if (preg_match('#^/admin/members/(\d+)/reactivate$#', $path, $matches) && $method === 'POST') {
        require_admin();
        $targetId = (int)$matches[1];
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role='customer' LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Member not found.'], 404);
        if ((bool)($target['is_active'] ?? true)) respond(['user' => user_payload($target)]);
        $update = db()->prepare('UPDATE users SET is_active=1,deactivated_at=NULL,deactivated_by=NULL,api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $update->execute([$targetId]);
        $read->execute([$targetId]);
        respond(['user' => user_payload($read->fetch())]);
    }

    if (preg_match('#^/admin/members/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        require_admin();
        $targetId = (int)$matches[1];
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role='customer' LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['deleted' => true]);

        // Preserve historical order records while removing the member account and member-only data.
        if (database_table_exists('body_profiles') && database_column_exists('body_profiles', 'user_id')) {
            $clearProfiles = db()->prepare('UPDATE body_profiles SET user_id=NULL WHERE user_id=?');
            $clearProfiles->execute([$targetId]);
        }
        $delete = db()->prepare("DELETE FROM users WHERE id=? AND role='customer'");
        $delete->execute([$targetId]);
        respond(['deleted' => true]);
    }

    if (preg_match('#^/admin/members/(\d+)/subscriptions$#', $path, $matches) && $method === 'GET') {
        require_admin();
        $targetId = (int)$matches[1];
        $memberCheck = db()->prepare("SELECT id FROM users WHERE id=? AND role='customer' LIMIT 1");
        $memberCheck->execute([$targetId]);
        if (!$memberCheck->fetch()) respond(['message' => 'Member not found.'], 404);
        $advisorStmt = db()->prepare('SELECT * FROM advisor_subscriptions WHERE user_id=? LIMIT 1');
        $advisorStmt->execute([$targetId]);
        $advisor = $advisorStmt->fetch();
        $productStmt = db()->prepare('SELECT * FROM product_subscriptions WHERE user_id=? ORDER BY created_at DESC,id DESC');
        $productStmt->execute([$targetId]);
        respond([
            'advisor' => $advisor ? advisor_subscription_payload($advisor) : null,
            'products' => array_map('product_subscription_payload', $productStmt->fetchAll()),
        ]);
    }

    if (preg_match('#^/admin/members/(\d+)/subscriptions/(advisor|product)/(\d+)/(pause|resume|cancel)$#', $path, $matches) && $method === 'POST') {
        require_admin();
        $targetId = (int)$matches[1];
        $kind = (string)$matches[2];
        $subscriptionId = (int)$matches[3];
        $action = (string)$matches[4];
        $memberCheck = db()->prepare("SELECT id FROM users WHERE id=? AND role='customer' LIMIT 1");
        $memberCheck->execute([$targetId]);
        if (!$memberCheck->fetch()) respond(['message' => 'Member not found.'], 404);

        if ($kind === 'advisor') {
            $read = db()->prepare('SELECT * FROM advisor_subscriptions WHERE id=? AND user_id=? LIMIT 1');
            $read->execute([$subscriptionId,$targetId]);
            $sub = $read->fetch();
            if (!$sub) respond(['message' => 'AI Health Coach subscription not found.'], 404);
            if ($action === 'pause') {
                db()->prepare("UPDATE advisor_subscriptions SET status='paused',cancel_at_period_end=0 WHERE id=?")->execute([$subscriptionId]);
            } elseif ($action === 'resume') {
                if ((string)$sub['status'] === 'cancelled') respond(['message' => 'A cancelled subscription cannot be resumed. Start a new order instead.'], 409);
                db()->prepare("UPDATE advisor_subscriptions SET status='active',cancel_at_period_end=0,cancelled_at=NULL WHERE id=?")->execute([$subscriptionId]);
            } else {
                db()->prepare("UPDATE advisor_subscriptions SET status='cancelled',cancel_at_period_end=0,pending_paid_conversion=0,cancelled_at=NOW(),current_period_end=NOW() WHERE id=?")->execute([$subscriptionId]);
            }
        } else {
            $read = db()->prepare('SELECT * FROM product_subscriptions WHERE id=? AND user_id=? LIMIT 1');
            $read->execute([$subscriptionId,$targetId]);
            $sub = $read->fetch();
            if (!$sub) respond(['message' => 'Product subscription not found.'], 404);
            if ($action === 'pause') {
                db()->prepare("UPDATE product_subscriptions SET status='paused',cancel_at_period_end=0 WHERE id=?")->execute([$subscriptionId]);
            } elseif ($action === 'resume') {
                if ((string)$sub['status'] === 'cancelled') respond(['message' => 'A cancelled subscription cannot be resumed. Start a new order instead.'], 409);
                db()->prepare("UPDATE product_subscriptions SET status='active',cancel_at_period_end=0 WHERE id=?")->execute([$subscriptionId]);
            } else {
                db()->prepare("UPDATE product_subscriptions SET status='cancelled',cancel_at_period_end=0,current_period_end=NOW() WHERE id=?")->execute([$subscriptionId]);
                $remaining = db()->prepare("SELECT COUNT(*) FROM product_subscriptions WHERE user_id=? AND id<>? AND status IN ('active','cancel_at_period_end') AND current_period_end>NOW()");
                $remaining->execute([$targetId,$subscriptionId]);
                if ((int)$remaining->fetchColumn() === 0) {
                    $advisor = ensure_advisor_subscription_for_user($targetId);
                    if ($advisor && (string)($advisor['status'] ?? '') !== 'cancelled') {
                        db()->prepare("UPDATE advisor_subscriptions SET pending_paid_conversion=1 WHERE id=?")->execute([(int)$advisor['id']]);
                    }
                }
            }
        }

        $advisorStmt = db()->prepare('SELECT * FROM advisor_subscriptions WHERE user_id=? LIMIT 1');
        $advisorStmt->execute([$targetId]);
        $advisor = $advisorStmt->fetch();
        $productStmt = db()->prepare('SELECT * FROM product_subscriptions WHERE user_id=? ORDER BY created_at DESC,id DESC');
        $productStmt->execute([$targetId]);
        respond([
            'advisor' => $advisor ? advisor_subscription_payload($advisor) : null,
            'products' => array_map('product_subscription_payload', $productStmt->fetchAll()),
        ]);
    }

    if ($method === 'GET' && $path === '/admin/orders') {
        require_admin();
        $rows = db()->query("SELECT o.*,p.slug AS stack_slug FROM orders o LEFT JOIN products p ON p.id=o.stack_product_id ORDER BY o.id DESC LIMIT 500")->fetchAll();
        respond(['orders' => array_map('order_payload', $rows)]);
    }

    if (preg_match('#^/admin/orders/(\d+)$#', $path, $matches) && $method === 'PUT') {
        require_admin();
        $input = json_input();
        $orderStatus = in_array((string)($input['orderStatus'] ?? ''), ['new','processing','completed','cancelled'], true) ? (string)$input['orderStatus'] : null;
        $fulfillment = in_array((string)($input['fulfillmentStatus'] ?? ''), ['unfulfilled','processing','shipped','delivered','cancelled'], true) ? (string)$input['fulfillmentStatus'] : null;
        if ($orderStatus === null || $fulfillment === null) respond(['message' => 'Valid order and fulfillment statuses are required.'], 422);
        $stmt = db()->prepare('UPDATE orders SET order_status=?,fulfillment_status=?,tracking_number=?,carrier=? WHERE id=?');
        $stmt->execute([$orderStatus,$fulfillment,trim((string)($input['trackingNumber'] ?? '')),trim((string)($input['carrier'] ?? '')),(int)$matches[1]]);
        $read = db()->prepare('SELECT o.*,p.slug AS stack_slug FROM orders o LEFT JOIN products p ON p.id=o.stack_product_id WHERE o.id=? LIMIT 1');
        $read->execute([(int)$matches[1]]);
        $row = $read->fetch();
        if (!$row) respond(['message' => 'Order not found.'], 404);
        respond(['order' => order_payload($row)]);
    }

    if (preg_match('#^/admin/orders/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        require_admin();
        $orderId = (int)$matches[1];
        $read = db()->prepare('SELECT id FROM orders WHERE id=? LIMIT 1');
        $read->execute([$orderId]);
        if (!$read->fetch()) respond(['deleted' => true]);
        // FK references from subscriptions use ON DELETE SET NULL so subscription history remains intact.
        $delete = db()->prepare('DELETE FROM orders WHERE id=?');
        $delete->execute([$orderId]);
        respond(['deleted' => true]);
    }

    if ($method === 'GET' && $path === '/admin/users') {
        require_admin();
        $role = trim((string)($_GET['role'] ?? ''));
        if ($role !== '' && !in_array($role, ['admin','reviewer'], true)) respond(['message' => 'Invalid staff role.'], 422);
        if ($role !== '') {
            $stmt = db()->prepare('SELECT * FROM users WHERE role=? ORDER BY is_active DESC, created_at ASC');
            $stmt->execute([$role]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = db()->query("SELECT * FROM users WHERE role IN ('admin','reviewer') ORDER BY is_active DESC, FIELD(role,'admin','reviewer'), created_at ASC")->fetchAll();
        }
        respond(['users' => array_map('staff_user_payload', $rows), 'activeAdminCount' => active_admin_count()]);
    }

    if ($method === 'GET' && $path === '/admin/staff-audit') {
        require_admin();
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $rows = db()->query('SELECT * FROM staff_audit_events ORDER BY created_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
        respond(['events' => array_map('staff_audit_payload', $rows)]);
    }

    if ($method === 'POST' && $path === '/admin/users') {
        $admin = require_admin();
        $input = json_input();
        foreach (['firstName','lastName','email','password'] as $key) {
            if (trim((string)($input[$key] ?? '')) === '') respond(['message' => "{$key} is required."], 422);
        }
        $role = trim((string)($input['role'] ?? 'admin'));
        if (!in_array($role, ['admin','reviewer'], true)) respond(['message' => 'Role must be admin or reviewer.'], 422);
        $email = strtolower(trim((string)$input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['message' => 'Enter a valid email address.'], 422);
        if (strlen((string)$input['password']) < 10) respond(['message' => 'Password must be at least 10 characters.'], 422);
        $existing = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $existing->execute([$email]);
        if ($existing->fetch()) respond(['message' => 'An account with this email already exists.'], 409);
        $stmt = db()->prepare('INSERT INTO users (email,password_hash,first_name,last_name,role,verified,is_active) VALUES (?,?,?,?,?,1,1)');
        $stmt->execute([$email, password_hash((string)$input['password'], PASSWORD_DEFAULT), trim((string)$input['firstName']), trim((string)$input['lastName']), $role]);
        $read = db()->prepare('SELECT * FROM users WHERE id=?');
        $read->execute([(int)db()->lastInsertId()]);
        $created = $read->fetch();
        staff_audit_event($admin, $created, 'created_staff', null, $role, ucfirst($role) . ' account created.');
        respond(['user' => staff_user_payload($created)], 201);
    }

    if (preg_match('#^/admin/users/(\\d+)/role$#', $path, $matches) && $method === 'PUT') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        if ((int)($admin['id'] ?? 0) > 0 && (int)$admin['id'] === $targetId) respond(['message' => 'You cannot change your own role while signed in.'], 422);
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','reviewer') LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Staff account not found.'], 404);
        $input = json_input();
        $newRole = trim((string)($input['role'] ?? ''));
        if (!in_array($newRole, ['admin','reviewer'], true)) respond(['message' => 'Role must be admin or reviewer.'], 422);
        $oldRole = (string)$target['role'];
        if ($oldRole === $newRole) respond(['user' => staff_user_payload($target)]);
        if ($oldRole === 'admin' && $newRole === 'reviewer' && (bool)($target['is_active'] ?? true) && active_admin_count() <= 1) {
            respond(['message' => 'You cannot demote the last active administrator. Create or reactivate another administrator first.'], 409);
        }
        $unassigned = 0;
        if ($oldRole === 'reviewer' && $newRole !== 'reviewer') {
            $unassigned = unassign_open_reviewer_cases($targetId, $admin, 'Reviewer role changed. Case returned to the review queue.');
        }
        $update = db()->prepare('UPDATE users SET role=?,api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $update->execute([$newRole, $targetId]);
        $read->execute([$targetId]);
        $updated = $read->fetch();
        $note = "Role changed from {$oldRole} to {$newRole}. Existing session invalidated.";
        if ($unassigned > 0) $note .= " {$unassigned} open case(s) returned to the review queue.";
        staff_audit_event($admin, $updated, 'changed_role', $oldRole, $newRole, $note);
        respond(['user' => staff_user_payload($updated), 'unassignedCases' => $unassigned]);
    }

    if (preg_match('#^/admin/users/(\\d+)/deactivate$#', $path, $matches) && $method === 'POST') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        if ((int)($admin['id'] ?? 0) > 0 && (int)$admin['id'] === $targetId) respond(['message' => 'You cannot deactivate your own signed-in account.'], 422);
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','reviewer') LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Staff account not found.'], 404);
        if (!(bool)($target['is_active'] ?? true)) respond(['user' => staff_user_payload($target), 'unassignedCases' => 0]);
        if ($target['role'] === 'admin' && active_admin_count() <= 1) respond(['message' => 'You cannot deactivate the last active administrator.'], 409);
        $unassigned = 0;
        if ($target['role'] === 'reviewer') {
            $unassigned = unassign_open_reviewer_cases($targetId, $admin, 'Reviewer account deactivated. Case returned to the review queue.');
        }
        $update = db()->prepare('UPDATE users SET is_active=0,deactivated_at=NOW(),deactivated_by=?,api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $actorId = (int)($admin['id'] ?? 0);
        $update->execute([$actorId > 0 ? $actorId : null, $targetId]);
        $read->execute([$targetId]);
        $updated = $read->fetch();
        $note = 'Account deactivated and existing session invalidated.';
        if ($unassigned > 0) $note .= " {$unassigned} open case(s) returned to the review queue.";
        staff_audit_event($admin, $updated, 'deactivated_staff', (string)$target['role'], (string)$target['role'], $note);
        respond(['user' => staff_user_payload($updated), 'unassignedCases' => $unassigned]);
    }

    if (preg_match('#^/admin/users/(\\d+)/reactivate$#', $path, $matches) && $method === 'POST') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','reviewer') LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Staff account not found.'], 404);
        if ((bool)($target['is_active'] ?? true)) respond(['user' => staff_user_payload($target)]);
        $update = db()->prepare('UPDATE users SET is_active=1,deactivated_at=NULL,deactivated_by=NULL,api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $update->execute([$targetId]);
        $read->execute([$targetId]);
        $updated = $read->fetch();
        staff_audit_event($admin, $updated, 'reactivated_staff', (string)$target['role'], (string)$target['role'], 'Account reactivated. A fresh login is required.');
        respond(['user' => staff_user_payload($updated)]);
    }

    if (preg_match('#^/admin/users/(\\d+)/reset-password$#', $path, $matches) && $method === 'POST') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        if ((int)($admin['id'] ?? 0) > 0 && (int)$admin['id'] === $targetId) respond(['message' => 'Use Change my password for your own account.'], 422);
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','reviewer') LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Staff account not found.'], 404);
        $input = json_input();
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 10) respond(['message' => 'Temporary password must be at least 10 characters.'], 422);
        if (password_verify($password, (string)$target['password_hash'])) respond(['message' => 'Choose a password different from the current password.'], 422);
        $update = db()->prepare('UPDATE users SET password_hash=?,api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
        staff_audit_event($admin, $target, 'reset_staff_password', (string)$target['role'], (string)$target['role'], 'Password reset by administrator. Existing session invalidated.');
        respond(['reset' => true]);
    }

    if (preg_match('#^/admin/users/(\\d+)/force-logout$#', $path, $matches) && $method === 'POST') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        if ((int)($admin['id'] ?? 0) > 0 && (int)$admin['id'] === $targetId) respond(['message' => 'Use Log out for your own account.'], 422);
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','reviewer') LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['message' => 'Staff account not found.'], 404);
        $update = db()->prepare('UPDATE users SET api_token_hash=NULL,api_token_expires_at=NULL WHERE id=?');
        $update->execute([$targetId]);
        staff_audit_event($admin, $target, 'forced_staff_logout', (string)$target['role'], (string)$target['role'], 'Existing staff session invalidated by administrator.');
        respond(['loggedOut' => true]);
    }

    if (preg_match('#^/admin/users/(\\d+)$#', $path, $matches) && $method === 'DELETE') {
        $admin = require_admin();
        $targetId = (int)$matches[1];
        if ((int)($admin['id'] ?? 0) > 0 && (int)$admin['id'] === $targetId) respond(['message' => 'You cannot delete your own signed-in account.'], 422);
        $read = db()->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','reviewer') LIMIT 1");
        $read->execute([$targetId]);
        $target = $read->fetch();
        if (!$target) respond(['deleted' => true]);
        if ($target['role'] === 'admin' && (bool)($target['is_active'] ?? true) && active_admin_count() <= 1) respond(['message' => 'You cannot delete the last active administrator.'], 409);
        $refs = staff_reference_counts($targetId);
        if (!$refs['canDelete']) {
            respond([
                'message' => 'This staff account has review history and cannot be permanently deleted. Deactivate it instead so the audit trail remains intact.',
                'references' => $refs,
            ], 409);
        }
        staff_audit_event($admin, $target, 'deleted_staff', (string)$target['role'], null, 'Unused staff account permanently deleted.');
        $clearRefs = db()->prepare('UPDATE users SET deactivated_by=NULL WHERE deactivated_by=?');
        $clearRefs->execute([$targetId]);
        $delete = db()->prepare('DELETE FROM users WHERE id=?');
        $delete->execute([$targetId]);
        respond(['deleted' => true]);
    }

    if ($method === 'GET' && $path === '/admin/overview') {
        require_admin();
        $stats = [
            'users' => (int)db()->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
            'paidOrders' => (int)db()->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid'")->fetchColumn(),
            'activeProducts' => (int)db()->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn(),
            'pendingReviews' => (int)db()->query("SELECT COUNT(*) FROM member_plans WHERE status IN ('needs_review','in_review','needs_information')")->fetchColumn(),
            'activeAdvisorSubscriptions' => (int)db()->query("SELECT COUNT(*) FROM advisor_subscriptions WHERE status IN ('active','cancel_at_period_end') AND current_period_end > NOW()")->fetchColumn(),
            'advisorMonthlyRevenue' => (float)db()->query("SELECT COALESCE(SUM(monthly_price),0) FROM advisor_subscriptions WHERE status IN ('active','cancel_at_period_end') AND current_period_end > NOW()")->fetchColumn(),
            'revenue' => (float)db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid'")->fetchColumn(),
        ];
        $recent = db()->query('SELECT mp.*,u.first_name,u.last_name,u.email FROM member_plans mp JOIN users u ON u.id=mp.user_id ORDER BY mp.updated_at DESC LIMIT 5')->fetchAll();
        $recentPlans = array_map(function (array $row): array {
            return [
                'planId' => (string)$row['id'],
                'customerId' => (string)$row['user_id'],
                'customerName' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'email' => (string)$row['email'],
                'primaryGoal' => (string)$row['goal'],
                'assessmentDate' => (string)$row['created_at'],
                'suggestedCategory' => implode(', ', decode_json_array($row['categories'] ?? null)),
                'flags' => decode_json_array($row['flags'] ?? null),
                'status' => (string)$row['status'],
                'reviewer' => (string)($row['reviewer'] ?? ''),
                'reviewerUserId' => !empty($row['reviewer_user_id']) ? (string)$row['reviewer_user_id'] : null,
            ];
        }, $recent);
        respond(['stats' => $stats, 'recentPlans' => $recentPlans]);
    }

    if ($method === 'GET' && $path === '/admin/plans') {
        $staff = require_staff();
        $status = trim((string)($_GET['status'] ?? ''));
        $sql = 'SELECT mp.*,u.first_name,u.last_name,u.email,o.assessment_json
            FROM member_plans mp
            JOIN users u ON u.id=mp.user_id
            LEFT JOIN orders o ON o.id=(SELECT MAX(o2.id) FROM orders o2 WHERE o2.user_id=u.id)';
        $where = [];
        $params = [];
        if ($status !== '' && $status !== 'all') { $where[] = 'mp.status = ?'; $params[] = $status; }
        if (($staff['role'] ?? '') === 'reviewer') {
            $where[] = '(mp.reviewer_user_id IS NULL OR mp.reviewer_user_id = ?)';
            $params[] = (int)$staff['id'];
        }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY CASE WHEN mp.reviewer_user_id = ? THEN 0 WHEN mp.reviewer_user_id IS NULL THEN 1 ELSE 2 END, mp.updated_at DESC';
        $params[] = (int)($staff['id'] ?? 0);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $plans = array_map(function (array $row): array {
            return [
                'planId' => (string)$row['id'],
                'customerId' => (string)$row['user_id'],
                'customerName' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'email' => (string)$row['email'],
                'primaryGoal' => (string)$row['goal'],
                'assessmentDate' => (string)$row['created_at'],
                'suggestedCategory' => implode(', ', decode_json_array($row['categories'] ?? null)),
                'flags' => decode_json_array($row['flags'] ?? null),
                'status' => (string)$row['status'],
                'reviewer' => (string)($row['reviewer'] ?? ''),
                'reviewerUserId' => !empty($row['reviewer_user_id']) ? (string)$row['reviewer_user_id'] : null,
            ];
        }, $stmt->fetchAll());
        respond(['plans' => $plans]);
    }

    if (preg_match('#^/admin/plans/(\d+)/claim$#', $path, $matches) && $method === 'POST') {
        $staff = require_staff();
        if (!in_array((string)($staff['role'] ?? ''), ['admin','reviewer'], true)) respond(['message' => 'Administrator or reviewer access is required to claim a case.'], 403);
        $planId = (int)$matches[1];
        $pdo = db();
        $pdo->beginTransaction();
        $read = $pdo->prepare('SELECT * FROM member_plans WHERE id=? FOR UPDATE');
        $read->execute([$planId]);
        $plan = $read->fetch();
        if (!$plan) { $pdo->rollBack(); respond(['message' => 'Plan not found.'], 404); }
        if (!empty($plan['reviewer_user_id']) && (int)$plan['reviewer_user_id'] !== (int)$staff['id']) {
            $pdo->rollBack();
            respond(['message' => 'This case is already assigned to another reviewer.'], 409);
        }
        $fromStatus = (string)$plan['status'];
        $toStatus = $fromStatus === 'needs_review' ? 'in_review' : $fromStatus;
        $name = staff_display_name($staff);
        $update = $pdo->prepare('UPDATE member_plans SET reviewer_user_id=?,reviewer=?,reviewer_assigned_at=COALESCE(reviewer_assigned_at,NOW()),status=? WHERE id=?');
        $update->execute([(int)$staff['id'], $name, $toStatus, $planId]);
        $pdo->commit();
        add_plan_review_event($planId, $staff, 'claimed_case', $fromStatus, $toStatus, 'Staff member claimed this case.');
        $fresh = db()->prepare('SELECT * FROM member_plans WHERE id=?');
        $fresh->execute([$planId]);
        respond(['plan' => plan_payload($fresh->fetch())]);
    }

    if (preg_match('#^/admin/plans/(\d+)/assignment$#', $path, $matches) && $method === 'PUT') {
        $admin = require_admin();
        $planId = (int)$matches[1];
        $input = json_input();
        $reviewerUserId = isset($input['reviewerUserId']) && trim((string)$input['reviewerUserId']) !== '' ? (int)$input['reviewerUserId'] : null;
        $currentStmt = db()->prepare('SELECT * FROM member_plans WHERE id=?');
        $currentStmt->execute([$planId]);
        $current = $currentStmt->fetch();
        if (!$current) respond(['message' => 'Plan not found.'], 404);
        $reviewerName = '';
        if ($reviewerUserId !== null) {
            $reviewerStmt = db()->prepare("SELECT * FROM users WHERE id=? AND role='reviewer' AND is_active=1 LIMIT 1");
            $reviewerStmt->execute([$reviewerUserId]);
            $reviewerRow = $reviewerStmt->fetch();
            if (!$reviewerRow) respond(['message' => 'Reviewer account not found.'], 404);
            $reviewerName = staff_display_name($reviewerRow);
        }
        $stmt = db()->prepare('UPDATE member_plans SET reviewer_user_id=?,reviewer=?,reviewer_assigned_at=? WHERE id=?');
        $stmt->execute([$reviewerUserId, $reviewerName, $reviewerUserId !== null ? date('Y-m-d H:i:s') : null, $planId]);
        add_plan_review_event($planId, $admin, $reviewerUserId !== null ? 'assigned_reviewer' : 'unassigned_reviewer', (string)$current['status'], (string)$current['status'], $reviewerName !== '' ? "Assigned to {$reviewerName}." : 'Reviewer assignment removed.');
        $fresh = db()->prepare('SELECT * FROM member_plans WHERE id=?');
        $fresh->execute([$planId]);
        respond(['plan' => plan_payload($fresh->fetch())]);
    }


    if (preg_match('#^/admin/body-profiles/([a-f0-9]{64})/image/(front|side|back)$#', $path, $matches) && $method === 'GET') {
        $staff = require_staff();
        $row = body_profile_row($matches[1]);
        if (!$row || (string)$row['status'] === 'deleted') respond(['message' => 'Body profile not found.'], 404);
        body_profile_require_staff_access($row, $staff);
        body_profile_stream_private_image($row, $matches[2]);
    }

    if (preg_match('#^/admin/body-profiles/([a-f0-9]{64})$#', $path, $matches) && $method === 'PUT') {
        $staff = require_staff();
        $row = body_profile_row($matches[1]);
        if (!$row || (string)$row['status'] === 'deleted') respond(['message' => 'Body profile not found.'], 404);
        body_profile_require_staff_access($row, $staff);
        $input = json_input();
        $status = (string)($input['status'] ?? $row['status']);
        if (!in_array($status, ['review_pending','approved','excluded'], true)) respond(['message' => 'Invalid body-profile review status.'], 422);
        $allowedTags = ['weight management','body composition','lean muscle','performance','recovery','mobility','posture support','general wellness'];
        $goalTags = is_array($input['goalTags'] ?? null) ? array_values(array_intersect($allowedTags, array_map('strval', $input['goalTags']))) : decode_json_array($row['goal_tags'] ?? null);
        $summary = trim((string)($input['visualSummary'] ?? $row['visual_summary'] ?? ''));
        $stmt = db()->prepare('UPDATE body_profiles SET visual_summary=?,goal_tags=?,status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?');
        $stmt->execute([$summary, json_encode($goalTags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, (int)$staff['id'], (int)$row['id']]);
        respond(['bodyProfile' => body_profile_payload(body_profile_row($matches[1]) ?: $row)]);
    }

    if (preg_match('#^/admin/plans/(\d+)$#', $path, $matches)) {
        $staff = require_staff();
        $planId = (int)$matches[1];
        if ($method === 'GET') {
            $stmt = db()->prepare('SELECT mp.*,u.first_name,u.last_name,u.email,o.assessment_json FROM member_plans mp JOIN users u ON u.id=mp.user_id LEFT JOIN orders o ON o.user_id=u.id WHERE mp.id=? ORDER BY o.id DESC LIMIT 1');
            $stmt->execute([$planId]);
            $row = $stmt->fetch();
            if (!$row) respond(['message' => 'Plan not found.'], 404);
            if (($staff['role'] ?? '') === 'reviewer' && !empty($row['reviewer_user_id']) && (int)$row['reviewer_user_id'] !== (int)$staff['id']) {
                respond(['message' => 'This case is assigned to another reviewer.'], 403);
            }
            $order = latest_order_for_user((int)$row['user_id']);
            $eventsStmt = db()->prepare('SELECT * FROM plan_review_events WHERE plan_id=? ORDER BY created_at DESC,id DESC LIMIT 100');
            $eventsStmt->execute([$planId]);
            $events = array_map(static function (array $event): array {
                return [
                    'id' => (string)$event['id'],
                    'planId' => (string)$event['plan_id'],
                    'actor' => (string)$event['actor_name'],
                    'actorRole' => (string)$event['actor_role'],
                    'action' => (string)$event['action'],
                    'fromStatus' => $event['from_status'] ?? null,
                    'toStatus' => $event['to_status'] ?? null,
                    'at' => (string)$event['created_at'],
                    'note' => (string)($event['note'] ?? ''),
                ];
            }, $eventsStmt->fetchAll());
            $reviewers = [];
            if (($staff['role'] ?? '') === 'admin') {
                $reviewers = array_map('user_payload', db()->query("SELECT * FROM users WHERE role='reviewer' AND is_active=1 ORDER BY first_name,last_name,email")->fetchAll());
            }
            $assessmentAnswers = json_decode((string)($row['assessment_json'] ?? '{}'), true) ?: [];
            $bodyProfileRow = body_profile_for_plan_assessment($assessmentAnswers);
            respond([
                'plan' => plan_payload($row),
                'customer' => ['id' => (string)$row['user_id'], 'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']), 'email' => (string)$row['email']],
                'answers' => $assessmentAnswers,
                'bodyProfile' => $bodyProfileRow ? body_profile_payload($bodyProfileRow) : null,
                'order' => $order ? order_payload($order) : null,
                'progress' => member_progress_payload((int)$row['user_id'], $row),
                'reviewEvents' => $events,
                'reviewers' => $reviewers,
                'canClaim' => in_array((string)($staff['role'] ?? ''), ['admin','reviewer'], true) && empty($row['reviewer_user_id']),
                'canEdit' => ($staff['role'] ?? '') === 'admin' || (($staff['role'] ?? '') === 'reviewer' && (int)($row['reviewer_user_id'] ?? 0) === (int)$staff['id']),
            ]);
        }
        if ($method === 'PUT') {
            $input = json_input();
            $currentStmt = db()->prepare('SELECT * FROM member_plans WHERE id=?');
            $currentStmt->execute([$planId]);
            $current = $currentStmt->fetch();
            if (!$current) respond(['message' => 'Plan not found.'], 404);
            if (($staff['role'] ?? '') === 'reviewer') {
                if (empty($current['reviewer_user_id'])) respond(['message' => 'Claim this case before editing it.'], 409);
                if ((int)$current['reviewer_user_id'] !== (int)$staff['id']) respond(['message' => 'This case is assigned to another reviewer.'], 403);
            }
            $allowedStatus = ['needs_review','in_review','needs_information','approved','released','rejected'];
            $status = (string)($input['status'] ?? $current['status'] ?? 'needs_review');
            if (!in_array($status, $allowedStatus, true)) respond(['message' => 'Invalid plan status.'], 422);
            $requestedInformation = trim((string)($input['requestedInformation'] ?? ''));
            if ($status === 'needs_information' && $requestedInformation === '') respond(['message' => 'Describe what information the member needs to provide.'], 422);
            $clearMemberResponse = $status === 'needs_information' && $status !== (string)($current['status'] ?? '');
            if ($status === 'released' && empty($current['reviewer_user_id'])) respond(['message' => 'Claim this case or assign a reviewer before publishing this plan.'], 422);
            $approvedAt = in_array($status, ['approved','released'], true) ? date('Y-m-d H:i:s') : null;
            $releasedAt = $status === 'released' ? date('Y-m-d H:i:s') : null;
            $workoutPlan = is_array($input['workoutPlan'] ?? null) ? array_values($input['workoutPlan']) : [];
            $mealPlan = is_array($input['mealPlan'] ?? null) ? array_values($input['mealPlan']) : [];
            $vitamins = is_array($input['vitamins'] ?? null) ? array_values($input['vitamins']) : [];
            $weeklyTargets = is_array($input['weeklyTargets'] ?? null) ? array_values($input['weeklyTargets']) : [];
            $milestones = is_array($input['milestones'] ?? null) ? array_values($input['milestones']) : decode_json_array($current['milestones'] ?? null);
            $reviewerName = (string)($current['reviewer'] ?? '');
            if (in_array((string)($staff['role'] ?? ''), ['admin','reviewer'], true) && (int)($current['reviewer_user_id'] ?? 0) === (int)$staff['id']) $reviewerName = staff_display_name($staff);
            $stmt = db()->prepare('UPDATE member_plans SET
                goal=?,focus=?,nutrition=?,activity=?,sleep=?,recovery=?,medication=?,dosage=?,package_name=?,
                workout_plan=?,meal_plan=?,vitamins=?,weekly_targets=?,milestones=?,reviewer_note=?,internal_reviewer_note=?,requested_information=?,
                member_response=IF(?,NULL,member_response),member_response_at=IF(?,NULL,member_response_at),status=?,reviewer=?,
                reviewer_approved_at=?,released_at=?,next_check_in=?,version=version+1
                WHERE id=?');
            $stmt->execute([
                trim((string)($input['goal'] ?? '')), trim((string)($input['focus'] ?? '')), trim((string)($input['nutrition'] ?? '')),
                trim((string)($input['activity'] ?? '')), trim((string)($input['sleep'] ?? '')), trim((string)($input['recovery'] ?? '')),
                trim((string)($input['medication'] ?? '')), trim((string)($input['dosage'] ?? '')), trim((string)($input['packageName'] ?? '')),
                json_encode($workoutPlan), json_encode($mealPlan), json_encode($vitamins), json_encode($weeklyTargets), json_encode($milestones),
                trim((string)($input['reviewerNote'] ?? '')), trim((string)($input['internalReviewerNote'] ?? '')), $requestedInformation, $clearMemberResponse ? 1 : 0, $clearMemberResponse ? 1 : 0, $status, $reviewerName,
                $approvedAt, $releasedAt, trim((string)($input['nextCheckIn'] ?? '')) !== '' ? (string)$input['nextCheckIn'] : null, $planId,
            ]);
            $fromStatus = (string)($current['status'] ?? 'needs_review');
            $action = $status === 'released' ? 'published_plan' : ($status !== $fromStatus ? 'changed_status' : 'saved_plan');
            $eventNote = trim((string)($input['internalReviewerNote'] ?? ''));
            if ($status === 'needs_information' && $requestedInformation !== '') $eventNote = $requestedInformation;
            add_plan_review_event($planId, $staff, $action, $fromStatus, $status, $eventNote);
            $read = db()->prepare('SELECT * FROM member_plans WHERE id=?');
            $read->execute([$planId]);
            $row = $read->fetch();
            respond(['plan' => plan_payload($row)]);
        }
    }

    if ($method === 'POST' && $path === '/me/nutrition-log') {
        $user = require_user();
        $input = json_input();
        $protein = max(0, min(1000, (float)($input['proteinGrams'] ?? 0)));
        $carbs = max(0, min(2000, (float)($input['carbsGrams'] ?? 0)));
        $hydration = max(0, min(1000, (float)($input['hydrationOz'] ?? 0)));
        $stmt = db()->prepare("INSERT INTO member_nutrition_logs (user_id,protein_grams,carbs_grams,hydration_oz,logged_on) VALUES (?,?,?,?,CURDATE()) ON DUPLICATE KEY UPDATE protein_grams=VALUES(protein_grams),carbs_grams=VALUES(carbs_grams),hydration_oz=VALUES(hydration_oz),updated_at=NOW()");
        $stmt->execute([(int)$user['id'],$protein,$carbs,$hydration]);
        $plan = member_plan_for_user((int)$user['id']);
        respond(['progress' => member_progress_payload((int)$user['id'], $plan ?: null)]);
    }

    if ($method === 'POST' && $path === '/me/meal-plan/regenerate') {
        $user = require_user();
        $plan = member_plan_for_user((int)$user['id']);
        if (!$plan || (string)($plan['status'] ?? '') !== 'released') respond(['message' => 'A released plan is required before changing meal suggestions.'], 409);
        $sets = [
            ['Greek yogurt with berries and oats','Grilled chicken grain bowl with mixed vegetables','Salmon, roasted potatoes and green vegetables','Apple with cottage cheese or a protein-rich alternative'],
            ['Egg and vegetable breakfast wrap','Turkey or tofu quinoa salad','Lean beef or tempeh stir-fry with rice and vegetables','Fruit with plain yogurt'],
            ['Overnight oats with chia and a protein source','Tuna or chickpea whole-grain wrap with salad','Chicken or lentil curry with rice and vegetables','Carrots with hummus'],
        ];
        $current = decode_json_array($plan['meal_plan'] ?? null);
        $choice = $sets[((int)$plan['version'] + count($current)) % count($sets)];
        $stmt = db()->prepare('UPDATE member_plans SET meal_plan=?,nutrition=?,version=version+1,updated_at=NOW() WHERE id=?');
        $stmt->execute([json_encode($choice), implode(' ', $choice), (int)$plan['id']]);
        $fresh = member_plan_for_user((int)$user['id']);
        respond(['plan' => member_plan_payload($fresh ?: $plan)]);
    }

    if ($method === 'GET' && $path === '/me/orders') {
        $user = require_user();
        $stmt = db()->prepare('SELECT o.*,p.slug AS stack_slug FROM orders o LEFT JOIN products p ON p.id=o.stack_product_id WHERE o.user_id=? ORDER BY o.id DESC');
        $stmt->execute([(int)$user['id']]);
        respond(['orders' => array_map('order_payload', $stmt->fetchAll())]);
    }

    if ($method === 'GET' && $path === '/me/product-subscriptions') {
        $user = require_user();
        $rows = active_product_subscriptions_for_user((int)$user['id']);
        respond(['subscriptions' => array_map('product_subscription_payload', $rows)]);
    }

    if (preg_match('#^/me/product-subscriptions/(\d+)/cancel$#', $path, $matches) && $method === 'POST') {
        $user = require_user();
        $stmt = db()->prepare("SELECT * FROM product_subscriptions WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([(int)$matches[1],(int)$user['id']]);
        $sub = $stmt->fetch();
        if (!$sub) respond(['message' => 'Product subscription not found.'], 404);
        $remainingStmt = db()->prepare("SELECT COUNT(*) FROM product_subscriptions WHERE user_id=? AND id<>? AND status IN ('active','cancel_at_period_end') AND current_period_end>NOW()");
        $remainingStmt->execute([(int)$user['id'],(int)$matches[1]]);
        $lastProduct = (int)$remainingStmt->fetchColumn() === 0;
        $input = json_input();
        if ($lastProduct && empty($input['confirmAdvisorPaidConversion'])) {
            $coachRow = db()->query("SELECT price FROM products WHERE slug='ai-health-advisor' LIMIT 1")->fetch();
            $coachMonthly = $coachRow ? (float)$coachRow['price'] : 19.99;
            respond(['requiresAdvisorPaidConversion' => true, 'message' => 'Cancelling your last product subscription will move AI Health Coach to the $' . number_format($coachMonthly, 2) . '/month paid plan when this product period ends. Confirm to continue.'], 409);
        }
        db()->prepare("UPDATE product_subscriptions SET cancel_at_period_end=1,status='cancel_at_period_end' WHERE id=?")->execute([(int)$sub['id']]);
        if ($lastProduct) {
            $advisor = ensure_advisor_subscription_for_user((int)$user['id']);
            if ($advisor) {
                db()->prepare("UPDATE advisor_subscriptions SET pending_paid_conversion=1 WHERE id=?")->execute([(int)$advisor['id']]);
            }
        }
        $read = db()->prepare('SELECT * FROM product_subscriptions WHERE id=?');
        $read->execute([(int)$sub['id']]);
        respond(['subscription' => product_subscription_payload($read->fetch()), 'advisorWillBecomePaid' => $lastProduct]);
    }

    if ($method === 'GET' && $path === '/me/subscription') {
        $user = require_user();
        $subscription = ensure_advisor_subscription_for_user((int)$user['id']);
        respond(['subscription' => $subscription ? advisor_subscription_payload($subscription) : null]);
    }

    if ($method === 'POST' && $path === '/me/subscription/cancel') {
        $user = require_user();
        $subscription = ensure_advisor_subscription_for_user((int)$user['id']);
        if (!$subscription) respond(['message' => 'No AI Health Coach subscription was found.'], 404);
        if ((string)$subscription['status'] === 'cancelled') respond(['subscription' => advisor_subscription_payload($subscription)]);
        $stmt = db()->prepare("UPDATE advisor_subscriptions SET cancel_at_period_end=1,status='cancel_at_period_end' WHERE id=?");
        $stmt->execute([(int)$subscription['id']]);
        $fresh = advisor_subscription_for_user((int)$user['id']);
        respond(['subscription' => advisor_subscription_payload($fresh ?: $subscription)]);
    }

    if ($method === 'POST' && $path === '/me/subscription/resume') {
        $user = require_user();
        $subscription = ensure_advisor_subscription_for_user((int)$user['id']);
        if (!$subscription) respond(['message' => 'No AI Health Coach subscription was found.'], 404);
        if ((string)$subscription['status'] === 'cancelled') respond(['message' => 'This subscription period has ended. Start a new checkout to reactivate AI Health Coach.'], 409);
        $stmt = db()->prepare("UPDATE advisor_subscriptions SET cancel_at_period_end=0,status='active',cancelled_at=NULL WHERE id=?");
        $stmt->execute([(int)$subscription['id']]);
        $fresh = advisor_subscription_for_user((int)$user['id']);
        respond(['subscription' => advisor_subscription_payload($fresh ?: $subscription)]);
    }

    if ($method === 'GET' && $path === '/me/advisor') {
        $user = require_user();
        $subscription = ensure_advisor_subscription_for_user((int)$user['id']);
        $active = advisor_has_active_access((int)$user['id']);
        respond([
            'enabled' => $active && openai_api_key() !== '',
            'entitled' => $active,
            'configured' => openai_api_key() !== '',
            'subscription' => $subscription ? advisor_subscription_payload($subscription) : null,
            'messages' => $active ? advisor_messages_for_user((int)$user['id'], 60) : [],
            'rateLimit' => $active ? advisor_rate_limit_status((int)$user['id']) : ['limit' => 30, 'used' => 0, 'remaining' => 0],
            'scope' => 'Health, wellness, purchased products, subscription/account and reviewer-published Thrivel IQ plan only.',
        ]);
    }

    if ($method === 'POST' && ($path === '/me/advisor/messages' || $path === '/chat')) {
        $user = require_user();
        if (!advisor_has_active_access((int)$user['id'])) respond(['message' => 'An active AI Health Coach monthly subscription is required.'], 403);
        if (openai_api_key() === '') respond(['message' => 'AI Health Coach is not configured yet. Add OPENAI_API_KEY to backend/.env.'], 503);
        $input = json_input();
        $message = trim((string)($input['message'] ?? ''));
        if ($message === '') respond(['message' => 'Message is required.'], 422);
        if (mb_strlen($message) > 4000) respond(['message' => 'Keep each message under 4,000 characters.'], 422);
        $rateLimit = advisor_rate_limit_status((int)$user['id']);
        if ($rateLimit['remaining'] <= 0) respond(['message' => 'AI Health Coach message limit reached. Try again in about an hour.'], 429);
        $generated = advisor_generate_reply((int)$user['id'], $message);
        $userMessage = advisor_store_message((int)$user['id'], 'user', $message);
        $assistantMessage = advisor_store_message((int)$user['id'], 'assistant', (string)$generated['reply'], (string)$generated['model'], (string)$generated['responseId'], (string)$generated['safetyClass']);
        respond(['userMessage' => $userMessage, 'assistantMessage' => $assistantMessage]);
    }

    if ($method === 'DELETE' && $path === '/me/advisor/messages') {
        $user = require_user();
        $stmt = db()->prepare('DELETE FROM advisor_messages WHERE user_id=?');
        $stmt->execute([(int)$user['id']]);
        respond(['deleted' => true]);
    }

    respond(['message' => 'Route not found.', 'path' => $path], 404);
} catch (Throwable $e) {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable) {
        // Keep the original exception.
    }
    $errorId = bin2hex(random_bytes(4));
    error_log("[Thrivel IQ {$errorId}] " . $e->__toString());
    if ($e instanceof OpenAIProviderException) {
        $status = $e->providerStatus;
        $httpStatus = $status === 429 ? 429 : (in_array($status, [401,403,404], true) ? 503 : 502);
        if ($e->retryAfterSeconds > 0) header('Retry-After: ' . $e->retryAfterSeconds);
        respond([
            'message' => $e->getMessage(),
            'errorId' => $errorId,
            'providerStatus' => $status ?: null,
            'providerCode' => $e->providerCode !== '' ? $e->providerCode : null,
            'retryAfterSeconds' => $e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : null,
        ], $httpStatus);
    }
    $message = 'Server error.';
    $detail = $e->getMessage();
    if (str_contains($detail, 'Base table or view not found')) $message = 'Database tables are missing or outdated. Upload this backend and reload; runtime migration will repair them.';
    elseif (str_contains($detail, 'Unknown column')) $message = 'Database columns are outdated. Upload this backend and reload; runtime migration will add them.';
    elseif (str_contains($detail, 'Access denied')) $message = 'Database login failed. Check DB_NAME, DB_USER and DB_PASSWORD in backend/.env.';
    elseif (str_contains($detail, 'Duplicate entry') && str_contains($detail, 'uq_users_email')) $message = 'An account with this email already exists.';
    respond([
        'message' => $message,
        'errorId' => $errorId,
        'detail' => env_value('APP_ENV') === 'development' ? $detail : null,
    ], 500);
}
