<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Fixtures;

/**
 * One catalogue, written four times: Latin, Cyrillic, Japanese, Thai.
 *
 * ── Why the same catalogue rather than four different ones ──────────────────
 *
 * Because a difference between two languages only means something if
 * everything else is held still. Source a French corpus, a Russian one and a
 * Japanese one and any gap between the results could be the writing system or
 * could be the corpus — twenty knives against two thousand novels against a
 * news archive — and nothing tells you which. Twenty products, the same twenty
 * products, in four scripts: then a property that holds in one and fails in
 * another is about the script.
 *
 * ── Why it is written and not extracted ────────────────────────────────────
 *
 * `benchmark/benchmark.php` measures against a real 45 000-product catalogue
 * and says of it: *the data itself belongs to the shop it came from and stays
 * there*. Taking twenty of its rows and committing them as fixtures would
 * break the rule this project set itself, so the Latin column is written here
 * too — from the same domain and with the same field shape, and owned by
 * nobody.
 *
 * ── What these are for, and what they are not for ──────────────────────────
 *
 * **Recall and precision, not relevance.** These fixtures cannot say whether
 * the first result is the one a Japanese shopper would have wanted; that needs
 * a native reader and a real catalogue, and it is out of reach. They answer the
 * question that *is* in reach and that silently goes wrong: **is the document
 * reachable at all**, and **does something unrelated come back**.
 *
 * That is the failure mode worth guarding. A poor ranking is visible the moment
 * anyone looks; a document no query can reach is invisible for the life of an
 * index. `ExpansionCapTest` found a real defect that way, on a synthetic
 * Chinese corpus and with no native judgement at all.
 *
 * So the assertions are properties, and their validity rests on lexical facts a
 * dictionary settles — that `ножи` is the plural of `нож`, that `折りたたみ`
 * really is kanji followed by okurigana — rather than on the sentences being
 * idiomatic. They are not idiomatic. They are correct, and they are the same
 * twenty products.
 *
 * ── The three code paths these four scripts cover ──────────────────────────
 *
 * Twenty-six scripts share three behaviours, and one representative of each is
 * what is needed rather than twenty-six corpora:
 *
 *   latin      space-separated, expandable        the path everything was tuned on
 *   cyrillic   the same path, another alphabet    do French-tuned constants transfer?
 *   japanese   continuous, and *mixes* scripts    kanji + okurigana in one word
 *   thai       continuous with no spaces at all   a whole phrase is one run
 *
 * Japanese is the interesting one. `折りたたみ` is Han, then Hiragana, then more
 * Hiragana, and Analyzer breaks a run at every change of script — so a word
 * built that way becomes several very short runs, and a run shorter than a
 * bigram is indexed whole. Single characters then expand through the character
 * gram index. Whether that stays precise is exactly what nobody has measured,
 * and `MixedScriptTest` is where it gets asked.
 */
final class MultilingualCatalogue
{
    public const SCRIPTS = ['latin', 'cyrillic', 'japanese', 'thai'];

    /**
     * The twenty products, in one script.
     *
     * Field shape taken from the reference catalogue: a name, a one-line
     * description, a brand, one category, a price and a stock. The
     * descriptions are one sentence rather than the 839 characters of real
     * catalogue HTML, because these fixtures test reachability and a longer
     * text would only make the file harder to check.
     *
     * @return array<string, array<string, mixed>> id => document
     */
    public static function products(string $script): array
    {
        return match ($script) {
            'latin'    => self::latin(),
            'cyrillic' => self::cyrillic(),
            'japanese' => self::japanese(),
            'thai'     => self::thai(),
            default    => throw new \InvalidArgumentException("No catalogue for '$script'"),
        };
    }

    /**
     * What each script's search has to be able to do.
     *
     * `find` is a query that must reach the listed ids — recall. `reject` is a
     * query that must *not* reach them, which is the half nobody tests and the
     * half that a query decomposing into single characters puts at risk.
     *
     * @return array<int, array{query: string, find: string[], reject: string[], why: string}>
     */
    public static function probes(string $script): array
    {
        return match ($script) {
            'latin'    => self::latinProbes(),
            'cyrillic' => self::cyrillicProbes(),
            'japanese' => self::japaneseProbes(),
            'thai'     => self::thaiProbes(),
            default    => throw new \InvalidArgumentException("No probes for '$script'"),
        };
    }

    // -------------------------------------------------------------------------
    // Latin — the path every constant in the engine was tuned against
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function latin(): array
    {
        return [
            'p01' => ['name' => 'Couteau pliant acier inoxydable', 'description' => 'Un couteau pliant de poche, lame en acier inoxydable et manche en bois.', 'brand' => 'Aubert', 'category' => 'Couteaux pliants', 'price' => 49.90, 'stock' => 12],
            'p02' => ['name' => 'Couteau de cuisine lame large', 'description' => 'Couteau de cuisine a lame large pour la decoupe des legumes.', 'brand' => 'Aubert', 'category' => 'Cuisine', 'price' => 34.50, 'stock' => 40],
            'p03' => ['name' => 'Couteaux de table, jeu de six', 'description' => 'Jeu de six couteaux de table, manche bois et lame acier.', 'brand' => 'Aubert', 'category' => 'Cuisine', 'price' => 89.00, 'stock' => 7],
            'p04' => ['name' => 'Couteau de chasse lame fixe', 'description' => 'Couteau de chasse a lame fixe, acier carbone et etui en cuir.', 'brand' => 'Forge du Nord', 'category' => 'Chasse', 'price' => 129.00, 'stock' => 5],
            'p05' => ['name' => 'Lame de rechange acier carbone', 'description' => 'Lame de rechange en acier carbone, longueur douze centimetres.', 'brand' => 'Forge du Nord', 'category' => 'Pieces', 'price' => 18.00, 'stock' => 60],
            'p06' => ['name' => 'Manche en bois de noyer', 'description' => 'Manche en bois de noyer, a monter sur une lame fixe.', 'brand' => 'Forge du Nord', 'category' => 'Pieces', 'price' => 22.00, 'stock' => 30],
            'p07' => ['name' => 'Pierre a aiguiser grain fin', 'description' => 'Pierre a aiguiser a grain fin pour entretenir le fil de la lame.', 'brand' => 'Perrin', 'category' => 'Entretien', 'price' => 27.50, 'stock' => 25],
            'p08' => ['name' => 'Aiguiseur de poche', 'description' => 'Aiguiseur de poche pour rattraper un tranchant en voyage.', 'brand' => 'Perrin', 'category' => 'Entretien', 'price' => 14.90, 'stock' => 80],
            'p09' => ['name' => 'Etui en cuir pour couteau pliant', 'description' => 'Etui en cuir souple, pour un couteau pliant de poche.', 'brand' => 'Perrin', 'category' => 'Accessoires', 'price' => 24.00, 'stock' => 18],
            'p10' => ['name' => 'Couteau pliant manche titane', 'description' => 'Couteau pliant leger, manche en titane et lame inoxydable.', 'brand' => 'Volt', 'category' => 'Couteaux pliants', 'price' => 179.00, 'stock' => 3],
            'p11' => ['name' => 'Couteau a pain lame dentee', 'description' => 'Couteau a pain, lame dentee en acier inoxydable.', 'brand' => 'Aubert', 'category' => 'Cuisine', 'price' => 29.90, 'stock' => 22],
            'p12' => ['name' => 'Couteau d office lame courte', 'description' => 'Petit couteau d office, lame courte et manche bois.', 'brand' => 'Aubert', 'category' => 'Cuisine', 'price' => 16.50, 'stock' => 45],
            'p13' => ['name' => 'Hachoir de cuisine acier', 'description' => 'Hachoir de cuisine en acier, pour herbes et legumes.', 'brand' => 'Aubert', 'category' => 'Cuisine', 'price' => 39.00, 'stock' => 14],
            'p14' => ['name' => 'Couteau de survie lame epaisse', 'description' => 'Couteau de survie a lame epaisse, acier carbone et manche cordelette.', 'brand' => 'Forge du Nord', 'category' => 'Chasse', 'price' => 98.00, 'stock' => 9],
            'p15' => ['name' => 'Ciseaux de cuisine acier', 'description' => 'Ciseaux de cuisine en acier inoxydable, lames demontables.', 'brand' => 'Perrin', 'category' => 'Cuisine', 'price' => 21.00, 'stock' => 33],
            'p16' => ['name' => 'Planche a decouper bois massif', 'description' => 'Planche a decouper en bois massif, pour la cuisine.', 'brand' => 'Perrin', 'category' => 'Cuisine', 'price' => 44.00, 'stock' => 11],
            'p17' => ['name' => 'Couteau pliant lame damas', 'description' => 'Couteau pliant a lame damas, manche bois et acier.', 'brand' => 'Volt', 'category' => 'Couteaux pliants', 'price' => 249.00, 'stock' => 2],
            'p18' => ['name' => 'Machette lame longue', 'description' => 'Machette a lame longue en acier carbone, manche caoutchouc.', 'brand' => 'Volt', 'category' => 'Outils', 'price' => 59.00, 'stock' => 16],
            'p19' => ['name' => 'Couteau a huitres lame courte', 'description' => 'Couteau a huitres, lame courte et garde de protection.', 'brand' => 'Perrin', 'category' => 'Cuisine', 'price' => 19.50, 'stock' => 27],
            'p20' => ['name' => 'Jeu de couteaux de cuisine', 'description' => 'Jeu de cinq couteaux de cuisine, acier inoxydable et bois.', 'brand' => 'Aubert', 'category' => 'Cuisine', 'price' => 149.00, 'stock' => 6],
        ];
    }

    /** @return array<int, array{query: string, find: string[], reject: string[], why: string}> */
    private static function latinProbes(): array
    {
        return [
            ['query' => 'couteau', 'find' => ['p01', 'p04', 'p10'], 'reject' => [],
             'why' => 'the plainest recall there is'],

            ['query' => 'couteaux', 'find' => ['p01'], 'reject' => [],
             'why' => 'a plural reaches the singular: one edit, and French inflects by suffix'],

            ['query' => 'coteau', 'find' => ['p01'], 'reject' => [],
             'why' => 'a dropped letter is inside the edit budget'],

            ['query' => 'inoxydable', 'find' => ['p01', 'p10'], 'reject' => ['p05'],
             'why' => 'p05 is carbon steel: a word the document does not hold must not be invented'],

            ['query' => 'aiguiser', 'find' => ['p07'], 'reject' => ['p01'],
             'why' => 'a query about upkeep must not return every knife in the shop'],
        ];
    }

    // -------------------------------------------------------------------------
    // Cyrillic — the same path, and the question is whether it transfers
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function cyrillic(): array
    {
        return [
            'p01' => ['name' => 'Складной нож нержавеющая сталь', 'description' => 'Складной карманный нож, лезвие из нержавеющей стали и деревянная рукоять.', 'brand' => 'Аубер', 'category' => 'Складные ножи', 'price' => 49.90, 'stock' => 12],
            'p02' => ['name' => 'Кухонный нож широкое лезвие', 'description' => 'Кухонный нож с широким лезвием для нарезки овощей.', 'brand' => 'Аубер', 'category' => 'Кухня', 'price' => 34.50, 'stock' => 40],
            'p03' => ['name' => 'Столовые ножи, набор из шести', 'description' => 'Набор из шести столовых ножей, деревянная рукоять и стальное лезвие.', 'brand' => 'Аубер', 'category' => 'Кухня', 'price' => 89.00, 'stock' => 7],
            'p04' => ['name' => 'Охотничий нож фиксированное лезвие', 'description' => 'Охотничий нож с фиксированным лезвием, углеродистая сталь и кожаный чехол.', 'brand' => 'Северная кузница', 'category' => 'Охота', 'price' => 129.00, 'stock' => 5],
            'p05' => ['name' => 'Сменное лезвие углеродистая сталь', 'description' => 'Сменное лезвие из углеродистой стали, длина двенадцать сантиметров.', 'brand' => 'Северная кузница', 'category' => 'Запчасти', 'price' => 18.00, 'stock' => 60],
            'p06' => ['name' => 'Рукоять из ореховой древесины', 'description' => 'Рукоять из ореховой древесины, для установки на фиксированное лезвие.', 'brand' => 'Северная кузница', 'category' => 'Запчасти', 'price' => 22.00, 'stock' => 30],
            'p07' => ['name' => 'Точильный камень мелкое зерно', 'description' => 'Точильный камень с мелким зерном для ухода за кромкой лезвия.', 'brand' => 'Перрен', 'category' => 'Уход', 'price' => 27.50, 'stock' => 25],
            'p08' => ['name' => 'Карманная точилка', 'description' => 'Карманная точилка, чтобы поправить остроту в поездке.', 'brand' => 'Перрен', 'category' => 'Уход', 'price' => 14.90, 'stock' => 80],
            'p09' => ['name' => 'Кожаный чехол для складного ножа', 'description' => 'Чехол из мягкой кожи, для карманного складного ножа.', 'brand' => 'Перрен', 'category' => 'Аксессуары', 'price' => 24.00, 'stock' => 18],
            'p10' => ['name' => 'Складной нож титановая рукоять', 'description' => 'Лёгкий складной нож, титановая рукоять и нержавеющее лезвие.', 'brand' => 'Вольт', 'category' => 'Складные ножи', 'price' => 179.00, 'stock' => 3],
            'p11' => ['name' => 'Хлебный нож зубчатое лезвие', 'description' => 'Хлебный нож, зубчатое лезвие из нержавеющей стали.', 'brand' => 'Аубер', 'category' => 'Кухня', 'price' => 29.90, 'stock' => 22],
            'p12' => ['name' => 'Малый нож короткое лезвие', 'description' => 'Малый нож, короткое лезвие и деревянная рукоять.', 'brand' => 'Аубер', 'category' => 'Кухня', 'price' => 16.50, 'stock' => 45],
            'p13' => ['name' => 'Кухонный тяпка сталь', 'description' => 'Кухонная тяпка из стали, для трав и овощей.', 'brand' => 'Аубер', 'category' => 'Кухня', 'price' => 39.00, 'stock' => 14],
            'p14' => ['name' => 'Нож для выживания толстое лезвие', 'description' => 'Нож для выживания с толстым лезвием, углеродистая сталь и шнуровая рукоять.', 'brand' => 'Северная кузница', 'category' => 'Охота', 'price' => 98.00, 'stock' => 9],
            'p15' => ['name' => 'Кухонные ножницы сталь', 'description' => 'Кухонные ножницы из нержавеющей стали, разъёмные лезвия.', 'brand' => 'Перрен', 'category' => 'Кухня', 'price' => 21.00, 'stock' => 33],
            'p16' => ['name' => 'Разделочная доска массив дерева', 'description' => 'Разделочная доска из массива дерева, для кухни.', 'brand' => 'Перрен', 'category' => 'Кухня', 'price' => 44.00, 'stock' => 11],
            'p17' => ['name' => 'Складной нож дамасское лезвие', 'description' => 'Складной нож с дамасским лезвием, деревянная рукоять и сталь.', 'brand' => 'Вольт', 'category' => 'Складные ножи', 'price' => 249.00, 'stock' => 2],
            'p18' => ['name' => 'Мачете длинное лезвие', 'description' => 'Мачете с длинным лезвием из углеродистой стали, резиновая рукоять.', 'brand' => 'Вольт', 'category' => 'Инструменты', 'price' => 59.00, 'stock' => 16],
            'p19' => ['name' => 'Нож для устриц короткое лезвие', 'description' => 'Нож для устриц, короткое лезвие и защитная гарда.', 'brand' => 'Перрен', 'category' => 'Кухня', 'price' => 19.50, 'stock' => 27],
            'p20' => ['name' => 'Набор кухонных ножей', 'description' => 'Набор из пяти кухонных ножей, нержавеющая сталь и дерево.', 'brand' => 'Аубер', 'category' => 'Кухня', 'price' => 149.00, 'stock' => 6],
        ];
    }

    /** @return array<int, array{query: string, find: string[], reject: string[], why: string}> */
    private static function cyrillicProbes(): array
    {
        return [
            ['query' => 'нож', 'find' => ['p01', 'p02', 'p04'], 'reject' => [],
             'why' => 'the plainest recall, in another alphabet'],

            // The property the whole Cyrillic column exists for. Russian
            // inflects by case and number, all of it suffixed, so prefix
            // completion should reach it — but MIN_PREFIX and the edit budget
            // were both chosen against French, and nothing had checked that
            // they carry over.
            ['query' => 'ножи', 'find' => ['p03'], 'reject' => [],
             'why' => 'a nominative plural must reach the genitive plural ножей'],

            ['query' => 'сталь', 'find' => ['p01', 'p05'], 'reject' => [],
             'why' => 'a nominative must reach the genitive стали, which is what the text says'],

            ['query' => 'нержавеющая', 'find' => ['p01', 'p10'], 'reject' => ['p05'],
             'why' => 'p05 is carbon steel; a long adjective must not match everything'],

            ['query' => 'точилка', 'find' => ['p08'], 'reject' => ['p01'],
             'why' => 'a sharpener query must not return every knife'],
        ];
    }

    // -------------------------------------------------------------------------
    // Japanese — continuous, and the only one that mixes scripts inside a word
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function japanese(): array
    {
        return [
            'p01' => ['name' => '折りたたみナイフ ステンレス鋼', 'description' => 'ポケット用の折りたたみナイフ、ステンレス鋼の刃と木製の柄。', 'brand' => 'オーベール', 'category' => '折りたたみナイフ', 'price' => 49.90, 'stock' => 12],
            'p02' => ['name' => '包丁 幅広の刃', 'description' => '野菜を切るための幅広の刃の包丁。', 'brand' => 'オーベール', 'category' => '料理', 'price' => 34.50, 'stock' => 40],
            'p03' => ['name' => 'テーブルナイフ 六本セット', 'description' => 'テーブルナイフ六本セット、木製の柄と鋼の刃。', 'brand' => 'オーベール', 'category' => '料理', 'price' => 89.00, 'stock' => 7],
            'p04' => ['name' => '狩猟ナイフ 固定刃', 'description' => '固定刃の狩猟ナイフ、炭素鋼と革製のケース。', 'brand' => '北の鍛冶', 'category' => '狩猟', 'price' => 129.00, 'stock' => 5],
            'p05' => ['name' => '交換用の刃 炭素鋼', 'description' => '炭素鋼の交換用の刃、全長十二センチ。', 'brand' => '北の鍛冶', 'category' => '部品', 'price' => 18.00, 'stock' => 60],
            'p06' => ['name' => 'くるみ材の柄', 'description' => 'くるみ材の柄、固定刃に取り付けるため。', 'brand' => '北の鍛冶', 'category' => '部品', 'price' => 22.00, 'stock' => 30],
            'p07' => ['name' => '砥石 細かい粒度', 'description' => '刃の切れ味を保つための細かい粒度の砥石。', 'brand' => 'ペラン', 'category' => '手入れ', 'price' => 27.50, 'stock' => 25],
            'p08' => ['name' => 'ポケット研ぎ器', 'description' => '旅行中に切れ味を直すためのポケット研ぎ器。', 'brand' => 'ペラン', 'category' => '手入れ', 'price' => 14.90, 'stock' => 80],
            'p09' => ['name' => '折りたたみナイフ用の革ケース', 'description' => 'ポケット用の折りたたみナイフのための柔らかい革のケース。', 'brand' => 'ペラン', 'category' => '付属品', 'price' => 24.00, 'stock' => 18],
            'p10' => ['name' => '折りたたみナイフ チタンの柄', 'description' => '軽い折りたたみナイフ、チタンの柄とステンレスの刃。', 'brand' => 'ボルト', 'category' => '折りたたみナイフ', 'price' => 179.00, 'stock' => 3],
            'p11' => ['name' => 'パン切りナイフ 波刃', 'description' => 'パン切りナイフ、ステンレス鋼の波刃。', 'brand' => 'オーベール', 'category' => '料理', 'price' => 29.90, 'stock' => 22],
            'p12' => ['name' => '小型ナイフ 短い刃', 'description' => '小型ナイフ、短い刃と木製の柄。', 'brand' => 'オーベール', 'category' => '料理', 'price' => 16.50, 'stock' => 45],
            'p13' => ['name' => '料理用の刻み器 鋼', 'description' => '鋼の料理用の刻み器、香草と野菜のため。', 'brand' => 'オーベール', 'category' => '料理', 'price' => 39.00, 'stock' => 14],
            'p14' => ['name' => 'サバイバルナイフ 厚い刃', 'description' => '厚い刃のサバイバルナイフ、炭素鋼と紐の柄。', 'brand' => '北の鍛冶', 'category' => '狩猟', 'price' => 98.00, 'stock' => 9],
            'p15' => ['name' => '料理はさみ 鋼', 'description' => 'ステンレス鋼の料理はさみ、取り外せる刃。', 'brand' => 'ペラン', 'category' => '料理', 'price' => 21.00, 'stock' => 33],
            'p16' => ['name' => 'まな板 無垢材', 'description' => '料理のための無垢材のまな板。', 'brand' => 'ペラン', 'category' => '料理', 'price' => 44.00, 'stock' => 11],
            'p17' => ['name' => '折りたたみナイフ ダマスカスの刃', 'description' => 'ダマスカスの刃の折りたたみナイフ、木製の柄と鋼。', 'brand' => 'ボルト', 'category' => '折りたたみナイフ', 'price' => 249.00, 'stock' => 2],
            'p18' => ['name' => 'マチェーテ 長い刃', 'description' => '炭素鋼の長い刃のマチェーテ、ゴムの柄。', 'brand' => 'ボルト', 'category' => '道具', 'price' => 59.00, 'stock' => 16],
            'p19' => ['name' => '牡蠣ナイフ 短い刃', 'description' => '牡蠣ナイフ、短い刃と保護のつば。', 'brand' => 'ペラン', 'category' => '料理', 'price' => 19.50, 'stock' => 27],
            'p20' => ['name' => '包丁セット', 'description' => '五本の包丁セット、ステンレス鋼と木。', 'brand' => 'オーベール', 'category' => '料理', 'price' => 149.00, 'stock' => 6],
        ];
    }

    /** @return array<int, array{query: string, find: string[], reject: string[], why: string}> */
    private static function japaneseProbes(): array
    {
        return [
            // Two characters of one script: the shape bigrams handle well, and
            // the one the existing tests already cover.
            ['query' => '包丁', 'find' => ['p02', 'p20'], 'reject' => [],
             'why' => 'a two-character Han word is exactly the bigram the documents produced'],

            ['query' => 'ステンレス', 'find' => ['p01', 'p10'], 'reject' => [],
             'why' => 'a katakana run of five characters, four bigrams, all present'],

            // ── The one this whole file was built to ask ──────────────────
            //
            // `折りたたみ` is Han, then four Hiragana. Analyzer breaks a run at
            // every change of script, so it becomes 折 (one character, indexed
            // whole) and りたたみ. A single character of a continuous script
            // then expands through the character gram index to *every bigram
            // holding it*.
            //
            // So this query asks for "some 折, and some りたたみ" rather than
            // for the word. Whether that stays precise is unmeasured, and it is
            // the commonest word shape in Japanese — kanji plus okurigana is
            // every verb and every adjective.
            ['query' => '折りたたみナイフ', 'find' => ['p01', 'p10', 'p17'], 'reject' => ['p02', 'p16'],
             'why' => 'kanji + okurigana + katakana in one word: it must find the folding knives and not the kitchen knife'],

            ['query' => '切れ味', 'find' => ['p07', 'p08'], 'reject' => ['p16'],
             'why' => 'another kanji-kana-kanji word; 味 must not drag in unrelated products'],

            ['query' => '炭素鋼', 'find' => ['p04', 'p05', 'p14', 'p18'], 'reject' => ['p02'],
             'why' => 'three Han characters, two bigrams; the stainless products must stay out'],
        ];
    }

    // -------------------------------------------------------------------------
    // Thai — continuous, and with no spaces inside a phrase at all
    // -------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function thai(): array
    {
        return [
            'p01' => ['name' => 'มีดพับสเตนเลส', 'description' => 'มีดพับสำหรับพกพา ใบมีดสเตนเลสและด้ามไม้', 'brand' => 'โอแบร์', 'category' => 'มีดพับ', 'price' => 49.90, 'stock' => 12],
            'p02' => ['name' => 'มีดครัวใบกว้าง', 'description' => 'มีดครัวใบกว้างสำหรับหั่นผัก', 'brand' => 'โอแบร์', 'category' => 'ครัว', 'price' => 34.50, 'stock' => 40],
            'p03' => ['name' => 'มีดโต๊ะอาหารชุดหกเล่ม', 'description' => 'มีดโต๊ะอาหารชุดหกเล่ม ด้ามไม้และใบมีดเหล็ก', 'brand' => 'โอแบร์', 'category' => 'ครัว', 'price' => 89.00, 'stock' => 7],
            'p04' => ['name' => 'มีดล่าสัตว์ใบมีดตรึง', 'description' => 'มีดล่าสัตว์ใบมีดตรึง เหล็กคาร์บอนและปลอกหนัง', 'brand' => 'ช่างเหล็กเหนือ', 'category' => 'ล่าสัตว์', 'price' => 129.00, 'stock' => 5],
            'p05' => ['name' => 'ใบมีดสำรองเหล็กคาร์บอน', 'description' => 'ใบมีดสำรองเหล็กคาร์บอน ความยาวสิบสองเซนติเมตร', 'brand' => 'ช่างเหล็กเหนือ', 'category' => 'อะไหล่', 'price' => 18.00, 'stock' => 60],
            'p06' => ['name' => 'ด้ามไม้วอลนัท', 'description' => 'ด้ามไม้วอลนัทสำหรับติดตั้งกับใบมีดตรึง', 'brand' => 'ช่างเหล็กเหนือ', 'category' => 'อะไหล่', 'price' => 22.00, 'stock' => 30],
            'p07' => ['name' => 'หินลับมีดเนื้อละเอียด', 'description' => 'หินลับมีดเนื้อละเอียดสำหรับรักษาคมใบมีด', 'brand' => 'เปแรง', 'category' => 'ดูแลรักษา', 'price' => 27.50, 'stock' => 25],
            'p08' => ['name' => 'ที่ลับมีดพกพา', 'description' => 'ที่ลับมีดพกพาสำหรับแก้ความคมระหว่างเดินทาง', 'brand' => 'เปแรง', 'category' => 'ดูแลรักษา', 'price' => 14.90, 'stock' => 80],
            'p09' => ['name' => 'ปลอกหนังสำหรับมีดพับ', 'description' => 'ปลอกหนังนุ่มสำหรับมีดพับพกพา', 'brand' => 'เปแรง', 'category' => 'อุปกรณ์เสริม', 'price' => 24.00, 'stock' => 18],
            'p10' => ['name' => 'มีดพับด้ามไทเทเนียม', 'description' => 'มีดพับน้ำหนักเบา ด้ามไทเทเนียมและใบมีดสเตนเลส', 'brand' => 'โวลต์', 'category' => 'มีดพับ', 'price' => 179.00, 'stock' => 3],
            'p11' => ['name' => 'มีดหั่นขนมปังใบหยัก', 'description' => 'มีดหั่นขนมปัง ใบหยักสเตนเลส', 'brand' => 'โอแบร์', 'category' => 'ครัว', 'price' => 29.90, 'stock' => 22],
            'p12' => ['name' => 'มีดเล็กใบสั้น', 'description' => 'มีดเล็ก ใบสั้นและด้ามไม้', 'brand' => 'โอแบร์', 'category' => 'ครัว', 'price' => 16.50, 'stock' => 45],
            'p13' => ['name' => 'มีดสับครัวเหล็ก', 'description' => 'มีดสับครัวเหล็กสำหรับสมุนไพรและผัก', 'brand' => 'โอแบร์', 'category' => 'ครัว', 'price' => 39.00, 'stock' => 14],
            'p14' => ['name' => 'มีดเอาชีวิตรอดใบหนา', 'description' => 'มีดเอาชีวิตรอดใบหนา เหล็กคาร์บอนและด้ามเชือก', 'brand' => 'ช่างเหล็กเหนือ', 'category' => 'ล่าสัตว์', 'price' => 98.00, 'stock' => 9],
            'p15' => ['name' => 'กรรไกรครัวเหล็ก', 'description' => 'กรรไกรครัวสเตนเลส ใบมีดถอดได้', 'brand' => 'เปแรง', 'category' => 'ครัว', 'price' => 21.00, 'stock' => 33],
            'p16' => ['name' => 'เขียงไม้เนื้อแข็ง', 'description' => 'เขียงไม้เนื้อแข็งสำหรับครัว', 'brand' => 'เปแรง', 'category' => 'ครัว', 'price' => 44.00, 'stock' => 11],
            'p17' => ['name' => 'มีดพับใบดามัสกัส', 'description' => 'มีดพับใบดามัสกัส ด้ามไม้และเหล็ก', 'brand' => 'โวลต์', 'category' => 'มีดพับ', 'price' => 249.00, 'stock' => 2],
            'p18' => ['name' => 'มีดพร้าใบยาว', 'description' => 'มีดพร้าใบยาวเหล็กคาร์บอน ด้ามยาง', 'brand' => 'โวลต์', 'category' => 'เครื่องมือ', 'price' => 59.00, 'stock' => 16],
            'p19' => ['name' => 'มีดแกะหอยนางรมใบสั้น', 'description' => 'มีดแกะหอยนางรม ใบสั้นและการ์ดป้องกัน', 'brand' => 'เปแรง', 'category' => 'ครัว', 'price' => 19.50, 'stock' => 27],
            'p20' => ['name' => 'ชุดมีดครัว', 'description' => 'ชุดมีดครัวห้าเล่ม สเตนเลสและไม้', 'brand' => 'โอแบร์', 'category' => 'ครัว', 'price' => 149.00, 'stock' => 6],
        ];
    }

    /** @return array<int, array{query: string, find: string[], reject: string[], why: string}> */
    private static function thaiProbes(): array
    {
        return [
            ['query' => 'มีดพับ', 'find' => ['p01', 'p09', 'p10', 'p17'], 'reject' => [],
             'why' => 'a folding-knife query, five characters, four bigrams'],

            ['query' => 'สเตนเลส', 'find' => ['p01', 'p10', 'p11'], 'reject' => [],
             'why' => 'stainless, seven characters, and every bigram of it is in the text'],

            ['query' => 'ไทเทเนียม', 'find' => ['p10'], 'reject' => ['p01'],
             'why' => 'titanium is in exactly one product; a whole-phrase run must not spread it'],

            // Thai puts no space between words at all, so an entire description
            // is one run and one long chain of bigrams. That is the extreme of
            // the continuous case: a short query matches a bigram anywhere in
            // the phrase, wherever the word boundary really was.
            ['query' => 'เขียง', 'find' => ['p16'], 'reject' => ['p01', 'p10'],
             'why' => 'a chopping board, and no knife may come with it'],

            ['query' => 'ลับมีด', 'find' => ['p07', 'p08'], 'reject' => ['p02'],
             'why' => 'sharpening: it must reach the stone and the pocket sharpener, not the kitchen knife'],
        ];
    }
}
