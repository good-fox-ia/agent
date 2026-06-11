<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Вбудовані голоси Gemini TTS (однакові для Flash і Pro, підтримують українську).
 */
enum TtsVoice: string
{
    case ZEPHYR = 'Zephyr';
    case PUCK = 'Puck';
    case CHARON = 'Charon';
    case KORE = 'Kore';
    case FENRIR = 'Fenrir';
    case LEDA = 'Leda';
    case ORUS = 'Orus';
    case AOEDE = 'Aoede';
    case CALLIRRHOE = 'Callirrhoe';
    case AUTONOE = 'Autonoe';
    case ENCELADUS = 'Enceladus';
    case IAPETUS = 'Iapetus';
    case UMBRIEL = 'Umbriel';
    case ALGIEBA = 'Algieba';
    case DESPINA = 'Despina';
    case ERINOME = 'Erinome';
    case ALGENIB = 'Algenib';
    case RASALGETHI = 'Rasalgethi';
    case LAOMEDEIA = 'Laomedeia';
    case ACHERNAR = 'Achernar';
    case ALNILAM = 'Alnilam';
    case SCHEDAR = 'Schedar';
    case GACRUX = 'Gacrux';
    case PULCHERRIMA = 'Pulcherrima';
    case ACHIRD = 'Achird';
    case ZUBENELGENUBI = 'Zubenelgenubi';
    case VINDEMIATRIX = 'Vindemiatrix';
    case SADACHBIA = 'Sadachbia';
    case SADALTAGER = 'Sadaltager';
    case SULAFAT = 'Sulafat';

    /** Короткий опис характеру звучання. */
    public function description(): string
    {
        return match ($this) {
            self::ZEPHYR => 'яскравий',
            self::PUCK => 'бадьорий',
            self::CHARON => 'інформативний',
            self::KORE => 'твердий',
            self::FENRIR => 'запальний',
            self::LEDA => 'юний',
            self::ORUS => 'впевнений',
            self::AOEDE => 'легкий',
            self::CALLIRRHOE => 'спокійний',
            self::AUTONOE => 'світлий',
            self::ENCELADUS => 'з придихом',
            self::IAPETUS => 'чіткий',
            self::UMBRIEL => 'розслаблений',
            self::ALGIEBA => 'плавний',
            self::DESPINA => 'м\'який плавний',
            self::ERINOME => 'ясний',
            self::ALGENIB => 'хрипкуватий',
            self::RASALGETHI => 'розповідний',
            self::LAOMEDEIA => 'жвавий',
            self::ACHERNAR => 'ніжний',
            self::ALNILAM => 'рішучий',
            self::SCHEDAR => 'рівний',
            self::GACRUX => 'зрілий',
            self::PULCHERRIMA => 'виразний',
            self::ACHIRD => 'дружній',
            self::ZUBENELGENUBI => 'невимушений',
            self::VINDEMIATRIX => 'лагідний',
            self::SADACHBIA => 'енергійний',
            self::SADALTAGER => 'обізнаний',
            self::SULAFAT => 'теплий',
        };
    }

    /** Підпис для inline-кнопки вибору голосу. */
    public function buttonLabel(): string
    {
        return $this->value . ' — ' . $this->description();
    }
}
