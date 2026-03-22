<?php
declare(strict_types=1);

namespace DakeLife\Services;

use DakeLife\Repositories\MessageTemplateRepository;
use DakeLife\Repositories\UserRepository;

final class MessageBuilderService
{
    private const PHASE_NAMES = [
        'sprout' => '芽吹き期',
        'flowing' => '巡り期',
        'plateau' => '足踏み期',
        'biased' => '偏り期',
        'challenge' => '挑戦期',
        'rest' => '休息期',
    ];

    private const AXIS_LABELS = [
        'encounter_score' => 'encounter',
        'comfort_score' => 'comfort',
        'challenge_score' => 'challenge',
        'flow_score' => 'flow',
    ];

    public function __construct(
        private ?MessageTemplateRepository $templateRepository = null,
        private ?UserRepository $userRepository = null
    ) {
        $this->templateRepository = $this->templateRepository ?: new MessageTemplateRepository();
        $this->userRepository = $this->userRepository ?: new UserRepository();
    }

    public function buildFromState(array $state, ?array $user = null): array
    {
        if ($user === null) {
            $dlUserId = (int) ($state['dl_user_id'] ?? 0);
            $user = $dlUserId > 0 ? $this->userRepository->findById($dlUserId) : null;
        }
        $user = is_array($user) ? $user : [];

        $scores = [
            'encounter_score' => (float) ($state['encounter_score'] ?? 50),
            'comfort_score' => (float) ($state['comfort_score'] ?? 50),
            'challenge_score' => (float) ($state['challenge_score'] ?? 50),
            'flow_score' => (float) ($state['flow_score'] ?? 50),
        ];
        $phaseCode = (string) ($state['phase_code'] ?? 'rest');
        $phaseName = self::PHASE_NAMES[$phaseCode] ?? $phaseCode;
        $totalScore = (float) ($state['total_score'] ?? 50);
        $recentActionCount = (int) ($state['recent_action_count'] ?? 0);
        $biasLevel = (float) ($state['bias_level'] ?? 0);

        [$topAxisKey, $topAxisValue] = $this->topAxis($scores);
        [$lowAxisKey, $lowAxisValue] = $this->lowAxis($scores);
        $hintAction = $this->hintAction($phaseCode, $recentActionCount, $biasLevel);

        $template = $this->templateRepository->findBestTemplate($phaseCode, $totalScore);
        $templateText = is_array($template) ? (string) ($template['template_text'] ?? '') : '';
        if ($templateText === '') {
            $templateText = '{display_name}さんの今の流れは「{phase_name}」です。次の一歩として {hint_action} を意識してみてください。';
        }

        $messageText = strtr($templateText, [
            '{display_name}' => (string) ($user['display_name'] ?? 'あなた'),
            '{phase_name}' => $phaseName,
            '{top_axis_name}' => self::AXIS_LABELS[$topAxisKey] ?? $topAxisKey,
            '{top_axis_score}' => (string) round($topAxisValue, 1),
            '{hint_action}' => $hintAction,
            '{total_score}' => (string) round($totalScore, 1),
        ]);

        return [
            'headline' => $this->headline($phaseCode, $phaseName),
            'phase_text' => $this->phaseText($phaseCode, $phaseName, $recentActionCount),
            'strong_point' => sprintf(
                '%s が %.1f まで伸びていて、今の強みになっています。',
                self::AXIS_LABELS[$topAxisKey] ?? $topAxisKey,
                $topAxisValue
            ),
            'warning' => $this->warning($phaseCode, $biasLevel, $lowAxisKey, $lowAxisValue),
            'suggestion' => '次の一歩は ' . $hintAction . ' を意識することです。',
            'message_text' => $messageText,
            'phase_code' => $phaseCode,
        ];
    }

    private function headline(string $phaseCode, string $phaseName): string
    {
        return match ($phaseCode) {
            'flowing' => $phaseName . 'で流れがつながっています',
            'challenge' => $phaseName . 'で変化を受け止めています',
            'biased' => $phaseName . 'で偏りが見えています',
            'plateau' => $phaseName . 'で落ち着いています',
            'sprout' => $phaseName . 'で変化が育ち始めています',
            default => $phaseName . 'で静かに整えています',
        };
    }

    private function phaseText(string $phaseCode, string $phaseName, int $recentActionCount): string
    {
        return match ($phaseCode) {
            'flowing' => $phaseName . 'です。無理なく行動がつながり、流れを感じやすい状態です。',
            'challenge' => $phaseName . 'です。新しい動きが多く、変化に踏み込めている状態です。',
            'biased' => $phaseName . 'です。同じ選択が続きやすく、偏り補正が入り始めています。',
            'plateau' => $phaseName . 'です。安定はありますが、変化の量はやや少なめです。',
            'sprout' => $phaseName . 'です。小さな新規行動が積み上がり始めています。',
            default => $phaseName . 'です。直近30日での行動数は ' . $recentActionCount . ' 件で、今は整える時間に寄っています。',
        };
    }

    private function warning(string $phaseCode, float $biasLevel, string $lowAxisKey, float $lowAxisValue): string
    {
        if ($phaseCode === 'biased' || $biasLevel >= 24.0) {
            return 'repeat 系の比率が高く、challenge と flow が落ちやすい状態です。';
        }

        return sprintf(
            '%s が %.1f と低めなので、その軸を補う行動を足すと全体が安定します。',
            self::AXIS_LABELS[$lowAxisKey] ?? $lowAxisKey,
            $lowAxisValue
        );
    }

    private function hintAction(string $phaseCode, int $recentActionCount, float $biasLevel): string
    {
        if ($phaseCode === 'biased' || $biasLevel >= 24.0) {
            return '初めての場所や新しい相手との接点';
        }
        if ($phaseCode === 'rest' || $recentActionCount <= 1) {
            return '10分だけの外出や短い連絡';
        }
        if ($phaseCode === 'challenge') {
            return '新しい行動のあとに安心できる定番行動';
        }
        if ($phaseCode === 'flowing') {
            return '今の流れを保つための小さな継続';
        }

        return 'いつもと少し違う行動';
    }

    private function topAxis(array $scores): array
    {
        $topKey = 'encounter_score';
        $topValue = -INF;
        foreach ($scores as $key => $value) {
            if ($value > $topValue) {
                $topKey = $key;
                $topValue = $value;
            }
        }
        return [$topKey, $topValue];
    }

    private function lowAxis(array $scores): array
    {
        $lowKey = 'encounter_score';
        $lowValue = INF;
        foreach ($scores as $key => $value) {
            if ($value < $lowValue) {
                $lowKey = $key;
                $lowValue = $value;
            }
        }
        return [$lowKey, $lowValue];
    }
}
