<?php

// ============================================================
//  GÉNÉRATEUR DE DONNÉES
// ============================================================

class DataGenerator
{
    private static array $categories   = ['Chaussures', 'Vêtements', 'Accessoires', 'Sport', 'Maison', 'Électronique'];
    private static array $marques      = ['Dupont', 'Martin', 'Bernard', 'Thomas', 'Petit', 'Robert', 'Richard', 'Simon', 'Leroy', 'Moreau', 'Nike', 'Adidas', 'Puma'];
    private static array $adjectifs    = ['élégant', 'confortable', 'robuste', 'léger', 'moderne', 'classique', 'artisanal', 'premium', 'naturel', 'durable', 'résistant', 'souple'];
    private static array $materiaux    = ['cuir', 'daim', 'toile', 'nylon', 'coton', 'laine', 'velours', 'satin', 'lin', 'caoutchouc', 'synthétique'];
    private static array $couleurs     = ['noir', 'blanc', 'marron', 'beige', 'rouge', 'bleu', 'vert', 'gris', 'bordeaux', 'camel', 'marine', 'crème'];
    private static array $produits     = ['chaussure', 'basket', 'sandale', 'mocassin', 'botte', 'escarpin', 'sneaker', 'derby', 'richelieu', 'mule', 'ballerine', 'bottine'];
    private static array $descriptions = [
        'Fabriqué avec soin par des artisans expérimentés.',
        'Conçu pour un confort optimal au quotidien.',
        'Matériaux de haute qualité sélectionnés avec attention.',
        'Design contemporain alliant style et fonctionnalité.',
        'Idéal pour toutes les occasions, du bureau à la ville.',
        'Finitions soignées et assemblage rigoureux.',
        'Une valeur sûre pour les amateurs de qualité.',
        'Coupe ajustée et semelle amortissante pour le confort.',
    ];
    private static array $tags = ['homme', 'femme', 'unisexe', 'luxe', 'sport', 'casual', 'été', 'hiver', 'printemps', 'automne', 'ville', 'randonnée'];

    public static function generate(int $count): array
    {
        $documents = [];
        for ($i = 0; $i < $count; $i++) {
            $produit  = self::pick(self::$produits);
            $materiau = self::pick(self::$materiaux);
            $couleur  = self::pick(self::$couleurs);
            $adjectif = self::pick(self::$adjectifs);
            $taille   = rand(36, 46);

            $documents[] = [
                'titre'       => ucfirst("$produit $materiau $couleur $adjectif taille $taille"),
                'description' => self::pick(self::$descriptions) . ' ' . self::pick(self::$descriptions),
                'categorie'   => self::pick(self::$categories),
                'marque'      => self::pick(self::$marques),
                'prix'        => round(rand(1990, 49990) / 100, 2),
                'stock'       => rand(0, 200),
                'actif'       => (bool) rand(0, 1),
                'tags'        => self::pickMany(self::$tags, rand(2, 4)),
            ];
        }
        return $documents;
    }

    private static function pick(array $arr): string  { return $arr[array_rand($arr)]; }
    private static function pickMany(array $arr, int $n): array { shuffle($arr); return array_slice($arr, 0, $n); }
}
