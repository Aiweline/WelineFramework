<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Session-learning reusable_rule MUST be abstract.
 * Special cases (one widget's CSS, one page, one site) belong only in examples.
 */
final class LearningReusableRuleGate
{
    /**
     * @return array{ok:bool,reason:string}
     */
    public static function judge(
        string $reusableRule,
        string $title = '',
        string $positiveExample = '',
        string $negativeExample = '',
    ): array {
        $rule = trim($reusableRule);
        if ($rule === '') {
            return ['ok' => false, 'reason' => 'empty_reusable_rule'];
        }

        $norm = static fn(string $value): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value),
            'UTF-8',
        );
        $ruleNorm = $norm($rule);
        $titleNorm = $title !== '' ? $norm($title) : '';
        $positiveNorm = $positiveExample !== '' ? $norm($positiveExample) : '';
        $negativeNorm = $negativeExample !== '' ? $norm($negativeExample) : '';

        if ($titleNorm !== '' && $ruleNorm === $titleNorm) {
            return ['ok' => false, 'reason' => 'reusable_rule_equals_title'];
        }
        if ($positiveNorm !== '' && $ruleNorm === $positiveNorm) {
            return ['ok' => false, 'reason' => 'reusable_rule_equals_positive_example'];
        }
        if ($negativeNorm !== '' && $ruleNorm === $negativeNorm) {
            return ['ok' => false, 'reason' => 'reusable_rule_equals_negative_example'];
        }
        if (str_starts_with($ruleNorm, 'verify this user-reported')) {
            return ['ok' => false, 'reason' => 'raw_user_claim_wrapper'];
        }
        if (preg_match('/[？?]/u', $rule) === 1) {
            return ['ok' => false, 'reason' => 'question_form_not_abstract_rule'];
        }
        if (preg_match('/^(不对啊?|那也不对|那不对|我靠|你的意思是|确定过了|修改规则，以后|没进入)/u', $rule) === 1) {
            return ['ok' => false, 'reason' => 'raw_chat_utterance_not_abstract_rule'];
        }
        if (preg_match('~(?:^|[\s`\'"])/(?:Users|home|var|tmp)/~u', $rule) === 1
            || preg_match('/\b[a-z0-9]{6,}\.test\.weline\.com\b/i', $rule) === 1) {
            return ['ok' => false, 'reason' => 'machine_or_host_concrete_in_rule'];
        }

        // Special-case masquerading as a general rule (user meaning of 具体当规则).
        // e.g. one widget's CSS / one widget path / one page class treated as the rule body.
        if (self::looksLikeSpecialCasePrescription($rule)) {
            return ['ok' => false, 'reason' => 'special_case_prescription_as_rule'];
        }

        return ['ok' => true, 'reason' => 'abstract_ok'];
    }

    /**
     * Detect when the rule body is a one-off special case rather than an abstract mechanism.
     */
    private static function looksLikeSpecialCasePrescription(string $rule): bool
    {
        // Single widget/statics CSS (or theme widget) file path as the prescription subject.
        if (preg_match('~(^|[\s`\'"])(?:(?:app/code/[^/\s]+/[^/\s]+/)?view/(?:statics/|theme/)?(?:(?:frontend|backend)/[^/\s]+/)?(?:css/)?)?widgets?/[\w./-]+\.(?:css|js|phtml)\b~iu', $rule) === 1) {
            return true;
        }

        $hasGeneralization = preg_match(
            '/(?:所有|凡|任何|同类|统一|通用|一律|必须抽象|机制|门禁|规范|约定|across|all\s+(?:widgets?|components?|surfaces?)|every\s+(?:widget|component)|MUST\s+for\s+all|forbid promoting one widget)/iu',
            $rule,
        ) === 1;
        $mentionsCss = preg_match('/\b(?:css|stylesheet|style|样式|风格|皮肤)\b/iu', $rule) === 1
            || preg_match('/\b(?:padding|margin|width|height|display|flex|gap|grid|position|z-index|font-size|color|background)\b/iu', $rule) === 1;

        // Exactly one real CSS class token AND the sentence is about that class's CSS/style,
        // with no generalization language → special case promoted to rule.
        preg_match_all('/\.[A-Za-z_][\w-]{1,80}\b/u', $rule, $classMatches);
        $classes = [];
        foreach ($classMatches[0] ?? [] as $token) {
            // Ignore file extensions mistaken for class selectors (.css / .js / .phtml).
            if (preg_match('/^\.(?:css|js|phtml|php|json|md|html|xml)$/i', $token) === 1) {
                continue;
            }
            $classes[] = $token;
        }
        $classes = array_values(array_unique($classes));
        if (count($classes) === 1 && $mentionsCss && !$hasGeneralization) {
            return true;
        }

        // Named single widget / 部件 as the only subject of a CSS/layout prescription.
        if (preg_match('/(?:部件|widget)\s*[「『"\']?([\w.-]{2,80})[」』"\']?/iu', $rule) === 1
            && $mentionsCss
            && !$hasGeneralization
            && preg_match('/(?:该部件|此部件|这个部件|该 widget|this widget|only\s+for)/iu', $rule) === 1) {
            return true;
        }

        // Explicit "for X only" / "只针对" special-case framing as the rule itself.
        if (preg_match('/(?:只针对|仅针对|仅限|只适用于|特例|仅此|only\s+for\s+this|this\s+one\s+(?:widget|component|page|file))/iu', $rule) === 1
            && !$hasGeneralization) {
            return true;
        }

        return false;
    }

    public static function isConcreteMasquerade(
        string $reusableRule,
        string $title = '',
        string $positiveExample = '',
        string $negativeExample = '',
    ): bool {
        return !self::judge($reusableRule, $title, $positiveExample, $negativeExample)['ok'];
    }
}
