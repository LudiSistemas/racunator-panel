<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/** Podaci o jednom izdanju sa GitHub Releases (samo ono što panel koristi). */
final class ReleaseInfo
{
    public function __construct(
        public readonly string $tag,
        public readonly string $version,
        public readonly string $name,
        public readonly string $body,
        public readonly ?string $publishedAt,
        public readonly ?string $htmlUrl,
        public readonly ?string $zipUrl,
        public readonly ?int $zipSize,
        public readonly ?string $sigUrl,
        public readonly ?string $sumsUrl,
        public readonly ?string $installerUrl,
    ) {
    }

    /** Iz odgovora GitHub API-ja (`/releases/latest`). Vraća null ako tag nije ispravna verzija. */
    public static function fromGithub(array $json): ?self
    {
        $tag = (string) ($json['tag_name'] ?? '');
        $version = ReleaseChecker::versionFromTag($tag);
        if ($version === null) {
            return null;
        }
        $zipName = 'racunator-panel-' . $version . '.zip';
        $assets = [];
        foreach ((array) ($json['assets'] ?? []) as $a) {
            if (is_array($a) && isset($a['name'], $a['browser_download_url'])) {
                $assets[(string) $a['name']] = $a;
            }
        }
        $url = static fn (string $n): ?string => isset($assets[$n]) ? (string) $assets[$n]['browser_download_url'] : null;
        return new self(
            $tag,
            $version,
            (string) ($json['name'] ?? $tag),
            (string) ($json['body'] ?? ''),
            isset($json['published_at']) ? (string) $json['published_at'] : null,
            isset($json['html_url']) ? (string) $json['html_url'] : null,
            $url($zipName),
            isset($assets[$zipName]['size']) ? (int) $assets[$zipName]['size'] : null,
            $url($zipName . '.sig'),
            $url('SHA256SUMS'),
            $url('racunator-panel-install.php'),
        );
    }

    public function zipName(): string
    {
        return 'racunator-panel-' . $this->version . '.zip';
    }

    /** Oblik koji se čuva u settings `latest_release_json`. */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): ?self
    {
        if (!isset($a['tag'], $a['version']) || ReleaseChecker::versionFromTag((string) $a['tag']) === null) {
            return null;
        }
        return new self(
            (string) $a['tag'],
            (string) $a['version'],
            (string) ($a['name'] ?? ''),
            (string) ($a['body'] ?? ''),
            isset($a['publishedAt']) ? (string) $a['publishedAt'] : null,
            isset($a['htmlUrl']) ? (string) $a['htmlUrl'] : null,
            isset($a['zipUrl']) ? (string) $a['zipUrl'] : null,
            isset($a['zipSize']) ? (int) $a['zipSize'] : null,
            isset($a['sigUrl']) ? (string) $a['sigUrl'] : null,
            isset($a['sumsUrl']) ? (string) $a['sumsUrl'] : null,
            isset($a['installerUrl']) ? (string) $a['installerUrl'] : null,
        );
    }
}
