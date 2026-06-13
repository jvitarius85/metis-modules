<?php
declare(strict_types=1);

namespace Metis\Modules\Resources;

use Metis\Modules\Website\Services\WebsiteRenderer;

final class Repository {
    public static function listSnapshot(): array {
        $db = \metis_db();
        $types_table = \Metis_Tables::get( 'resource_types' );
        $categories_table = \Metis_Tables::get( 'resource_categories' );
        $tags_table = \Metis_Tables::get( 'resource_tags' );
        $resources_table = \Metis_Tables::get( 'resources' );
        $resource_tags_table = \Metis_Tables::get( 'resource_tag_map' );
        $resource_categories_table = \Metis_Tables::get( 'resource_category_map' );

        $types = $db->fetchAll( "SELECT * FROM {$types_table} ORDER BY is_active DESC, sort_order ASC, name ASC" ) ?: [];
        $categories = $db->fetchAll(
            "SELECT c.*, t.name AS type_name
             FROM {$categories_table} c
             INNER JOIN {$types_table} t ON t.id = c.resource_type_id
             ORDER BY t.name ASC, c.sort_order ASC, c.name ASC"
        ) ?: [];
        $tags = $db->fetchAll(
            "SELECT g.*, t.name AS type_name
             FROM {$tags_table} g
             INNER JOIN {$types_table} t ON t.id = g.resource_type_id
             ORDER BY t.name ASC, g.sort_order ASC, g.name ASC"
        ) ?: [];
        $resources = $db->fetchAll(
            "SELECT r.*, t.name AS type_name, c.name AS primary_category_name
             FROM {$resources_table} r
             INNER JOIN {$types_table} t ON t.id = r.resource_type_id
             LEFT JOIN {$categories_table} c ON c.id = r.primary_category_id
             ORDER BY r.is_featured DESC, r.sort_order ASC, r.updated_at DESC, r.title ASC"
        ) ?: [];

        $category_map = self::categoryNamesByResourceId();
        $tag_map = self::tagNamesByResourceId();
        $category_id_map = self::categoryIdsMapByResourceId();
        $tag_id_map = self::tagIdsMapByResourceId();

        foreach ( $resources as &$row ) {
            $id = (int) ( $row['id'] ?? 0 );
            $row['category_names'] = $category_map[ $id ] ?? [];
            $row['tag_names'] = $tag_map[ $id ] ?? [];
            $row['category_ids'] = $category_id_map[ $id ] ?? [];
            $row['tag_ids'] = $tag_id_map[ $id ] ?? [];
            $row['attachments'] = self::normalizeAttachmentList( (string) ( $row['attachments_json'] ?? '[]' ) );
        }
        unset( $row );

        return [
            'types' => $types,
            'categories' => $categories,
            'tags' => $tags,
            'resources' => $resources,
            'type_options' => self::typeOptions(),
            'category_options' => self::categoryOptions(),
            'tag_options' => self::tagOptions(),
            'stats' => [
                'types' => count( $types ),
                'categories' => count( $categories ),
                'tags' => count( $tags ),
                'resources' => count( $resources ),
                'published_resources' => count( array_filter( $resources, static fn ( array $row ): bool => (string) ( $row['status'] ?? 'draft' ) === 'published' ) ),
                'featured_resources' => count( array_filter( $resources, static fn ( array $row ): bool => ! empty( $row['is_featured'] ) ) ),
            ],
        ];
    }

    public static function typeOptions(): array {
        return \metis_db()->fetchAll(
            "SELECT id, name, slug FROM " . \Metis_Tables::get( 'resource_types' ) . " WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
        ) ?: [];
    }

    public static function categoryOptions( int $type_id = 0 ): array {
        $table = \Metis_Tables::get( 'resource_categories' );
        $sql = "SELECT id, resource_type_id, name, slug FROM {$table}";
        $params = [];
        if ( $type_id > 0 ) {
            $sql .= " WHERE resource_type_id = %d AND is_active = 1";
            $params[] = $type_id;
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        return \metis_db()->fetchAll( $sql, $params ) ?: [];
    }

    public static function tagOptions( int $type_id = 0 ): array {
        $table = \Metis_Tables::get( 'resource_tags' );
        $sql = "SELECT id, resource_type_id, name, slug FROM {$table}";
        $params = [];
        if ( $type_id > 0 ) {
            $sql .= " WHERE resource_type_id = %d AND is_active = 1";
            $params[] = $type_id;
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        return \metis_db()->fetchAll( $sql, $params ) ?: [];
    }

    public static function saveType( array $data, int $user_id ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'resource_types' );
        $id = max( 0, (int) ( $data['id'] ?? 0 ) );
        $name = trim( (string) ( $data['name'] ?? '' ) );
        $slug = self::uniqueSlug( $table, trim( (string) ( $data['slug'] ?? '' ) ) !== '' ? (string) $data['slug'] : $name, $id );
        if ( $name === '' || $slug === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Type name is required.' ];
        }

        $payload = [
            'name' => $name,
            'slug' => $slug,
            'intro_html' => WebsiteRenderer::sanitizePublicRichText( (string) ( $data['intro_html'] ?? '' ) ),
            'seo_title' => self::nullable( (string) ( $data['seo_title'] ?? '' ) ),
            'seo_description' => self::nullable( (string) ( $data['seo_description'] ?? '' ) ),
            'is_active' => ! empty( $data['is_active'] ) ? 1 : 0,
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'updated_by' => $user_id > 0 ? $user_id : null,
        ];

        if ( $id > 0 ) {
            $db->update( $table, $payload, [ 'id' => $id ] );
        } else {
            $payload['created_by'] = $user_id > 0 ? $user_id : null;
            if ( \function_exists( 'metis_entity_id_service' ) ) {
                $payload = \metis_entity_id_service()->assignForInsert( 'resource_type', $payload );
            } else {
                $payload['resource_type_uid'] = \metis_generate_code( 'RTY', $table, 'resource_type_uid' );
                $payload['resource_type_code'] = \metis_generate_code( 'RTY', $table, 'resource_type_code' );
            }
            $db->insert( $table, $payload );
            $id = (int) $db->lastInsertId();
        }

        return [ 'ok' => true, 'id' => $id ];
    }

    public static function saveCategory( array $data, int $user_id ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'resource_categories' );
        $id = max( 0, (int) ( $data['id'] ?? 0 ) );
        $type_id = max( 0, (int) ( $data['resource_type_id'] ?? 0 ) );
        $name = trim( (string) ( $data['name'] ?? '' ) );
        if ( $type_id < 1 || $name === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Category type and name are required.' ];
        }
        $slug = self::uniqueScopedSlug( $table, 'resource_type_id', $type_id, (string) ( $data['slug'] ?? $name ), $id );

        $payload = [
            'resource_type_id' => $type_id,
            'name' => $name,
            'slug' => $slug,
            'intro_html' => WebsiteRenderer::sanitizePublicRichText( (string) ( $data['intro_html'] ?? '' ) ),
            'seo_title' => self::nullable( (string) ( $data['seo_title'] ?? '' ) ),
            'seo_description' => self::nullable( (string) ( $data['seo_description'] ?? '' ) ),
            'is_active' => ! empty( $data['is_active'] ) ? 1 : 0,
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'updated_by' => $user_id > 0 ? $user_id : null,
        ];
        if ( $id > 0 ) {
            $db->update( $table, $payload, [ 'id' => $id ] );
        } else {
            $payload['created_by'] = $user_id > 0 ? $user_id : null;
            $payload['resource_category_uid'] = \metis_generate_code( 'RCA', $table, 'resource_category_uid' );
            $payload['resource_category_code'] = \metis_generate_code( 'RCA', $table, 'resource_category_code' );
            $db->insert( $table, $payload );
            $id = (int) $db->lastInsertId();
        }
        return [ 'ok' => true, 'id' => $id ];
    }

    public static function saveTag( array $data, int $user_id ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'resource_tags' );
        $id = max( 0, (int) ( $data['id'] ?? 0 ) );
        $type_id = max( 0, (int) ( $data['resource_type_id'] ?? 0 ) );
        $name = trim( (string) ( $data['name'] ?? '' ) );
        if ( $type_id < 1 || $name === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Tag type and name are required.' ];
        }
        $slug = self::uniqueScopedSlug( $table, 'resource_type_id', $type_id, (string) ( $data['slug'] ?? $name ), $id );
        $payload = [
            'resource_type_id' => $type_id,
            'name' => $name,
            'slug' => $slug,
            'is_active' => ! empty( $data['is_active'] ) ? 1 : 0,
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'updated_by' => $user_id > 0 ? $user_id : null,
        ];
        if ( $id > 0 ) {
            $db->update( $table, $payload, [ 'id' => $id ] );
        } else {
            $payload['created_by'] = $user_id > 0 ? $user_id : null;
            $payload['resource_tag_uid'] = \metis_generate_code( 'RTG', $table, 'resource_tag_uid' );
            $payload['resource_tag_code'] = \metis_generate_code( 'RTG', $table, 'resource_tag_code' );
            $db->insert( $table, $payload );
            $id = (int) $db->lastInsertId();
        }
        return [ 'ok' => true, 'id' => $id ];
    }

    public static function saveResource( array $data, array $files, int $user_id ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'resources' );
        $categories_map = \Metis_Tables::get( 'resource_category_map' );
        $tags_map = \Metis_Tables::get( 'resource_tag_map' );
        $id = max( 0, (int) ( $data['id'] ?? 0 ) );
        $type_id = max( 0, (int) ( $data['resource_type_id'] ?? 0 ) );
        $title = trim( (string) ( $data['title'] ?? '' ) );
        if ( $type_id < 1 || $title === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Resource type and title are required.' ];
        }
        $primary_category_id = max( 0, (int) ( $data['primary_category_id'] ?? 0 ) );
        $slug = self::uniqueScopedSlug( $table, 'resource_type_id', $type_id, (string) ( $data['slug'] ?? $title ), $id );

        $logo = self::handleUpload( $files['logo_file'] ?? null, 'resources', 'resource_logo' );
        $existing_logo_token = trim( (string) ( $data['existing_logo_token'] ?? '' ) );
        $existing_logo_url = trim( (string) ( $data['existing_logo_url'] ?? '' ) );
        if ( empty( $logo['ok'] ) ) {
            $logo = [ 'ok' => true, 'token' => $existing_logo_token, 'url' => $existing_logo_url ];
        }

        $attachments = self::normalizeAttachmentList( (string) ( $data['existing_attachments_json'] ?? '[]' ) );
        $uploaded_files = self::normalizeFileArray( $files['resource_files'] ?? null );
        foreach ( $uploaded_files as $file ) {
            $stored = self::handleUpload( $file, 'resources', 'resource_file' );
            if ( ! empty( $stored['ok'] ) ) {
                $attachments[] = [
                    'token' => (string) ( $stored['token'] ?? '' ),
                    'url' => (string) ( $stored['url'] ?? '' ),
                    'name' => (string) ( $stored['file_name'] ?? '' ),
                ];
            }
        }

        $status = \metis_key_clean( (string) ( $data['status'] ?? 'draft' ) );
        if ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
            $status = 'draft';
        }
        $review_due_at = self::normalizeDateTime( (string) ( $data['review_due_at'] ?? '' ) );
        if ( $review_due_at !== null && strtotime( $review_due_at ) < time() ) {
            $status = 'draft';
        }

        $payload = [
            'resource_type_id' => $type_id,
            'primary_category_id' => $primary_category_id > 0 ? $primary_category_id : null,
            'title' => $title,
            'slug' => $slug,
            'organization_name' => self::nullable( (string) ( $data['organization_name'] ?? '' ) ),
            'summary' => self::nullable( (string) ( $data['summary'] ?? '' ) ),
            'description_html' => WebsiteRenderer::sanitizePublicRichText( (string) ( $data['description_html'] ?? '' ) ),
            'website_url' => self::nullableUrl( (string) ( $data['website_url'] ?? '' ) ),
            'phone' => self::nullable( (string) ( $data['phone'] ?? '' ) ),
            'email' => self::nullableEmail( (string) ( $data['email'] ?? '' ) ),
            'logo_media_token' => self::nullable( (string) ( $logo['token'] ?? '' ) ),
            'logo_url' => self::nullableUrl( (string) ( $logo['url'] ?? '' ) ),
            'attachments_json' => \metis_json_encode( $attachments ),
            'eligibility_notes' => self::nullable( (string) ( $data['eligibility_notes'] ?? '' ) ),
            'address_line1' => self::nullable( (string) ( $data['address_line1'] ?? '' ) ),
            'city' => self::nullable( (string) ( $data['city'] ?? '' ) ),
            'state_code' => self::nullable( strtoupper( trim( (string) ( $data['state_code'] ?? '' ) ) ) ),
            'county' => self::nullable( (string) ( $data['county'] ?? '' ) ),
            'postal_code' => self::nullable( (string) ( $data['postal_code'] ?? '' ) ),
            'service_radius' => self::nullable( (string) ( $data['service_radius'] ?? '' ) ),
            'is_online' => ! empty( $data['is_online'] ) ? 1 : 0,
            'review_due_at' => $review_due_at,
            'expires_at' => self::normalizeDateTime( (string) ( $data['expires_at'] ?? '' ) ),
            'is_featured' => ! empty( $data['is_featured'] ) ? 1 : 0,
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'status' => $status,
            'updated_by' => $user_id > 0 ? $user_id : null,
        ];

        if ( $id > 0 ) {
            $db->update( $table, $payload, [ 'id' => $id ] );
        } else {
            $payload['created_by'] = $user_id > 0 ? $user_id : null;
            $payload['resource_uid'] = \metis_generate_code( 'RSC', $table, 'resource_uid' );
            $payload['resource_code'] = \metis_generate_code( 'RSC', $table, 'resource_code' );
            $db->insert( $table, $payload );
            $id = (int) $db->lastInsertId();
        }

        $category_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $data['category_ids'] ?? [] ) ) ) ) );
        if ( $primary_category_id > 0 && ! in_array( $primary_category_id, $category_ids, true ) ) {
            array_unshift( $category_ids, $primary_category_id );
        }
        $tag_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $data['tag_ids'] ?? [] ) ) ) ) );

        $db->delete( $categories_map, [ 'resource_id' => $id ] );
        foreach ( $category_ids as $category_id ) {
            $db->insert( $categories_map, [
                'resource_id' => $id,
                'category_id' => $category_id,
                'is_primary' => $primary_category_id > 0 && $category_id === $primary_category_id ? 1 : 0,
            ] );
        }

        $db->delete( $tags_map, [ 'resource_id' => $id ] );
        foreach ( $tag_ids as $tag_id ) {
            $db->insert( $tags_map, [ 'resource_id' => $id, 'tag_id' => $tag_id ] );
        }

        return [ 'ok' => true, 'id' => $id ];
    }

    public static function deleteRecord( string $kind, int $id ): array {
        $kind = \metis_key_clean( $kind );
        $map = [
            'type' => \Metis_Tables::get( 'resource_types' ),
            'category' => \Metis_Tables::get( 'resource_categories' ),
            'tag' => \Metis_Tables::get( 'resource_tags' ),
            'resource' => \Metis_Tables::get( 'resources' ),
        ];
        if ( $id < 1 || ! isset( $map[ $kind ] ) ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Invalid delete request.' ];
        }
        \metis_db()->delete( $map[ $kind ], [ 'id' => $id ] );
        return [ 'ok' => true ];
    }

    public static function renderPublicRoute( string $type_slug, string $category_slug, string $resource_slug, array $query ): ?string {
        if ( $type_slug === '' ) {
            return self::renderLandingPage();
        }

        $type = self::publicTypeBySlug( $type_slug );
        if ( ! is_array( $type ) ) {
            return null;
        }

        if ( $resource_slug !== '' ) {
            $resource = self::publicResourceByPath( (int) $type['id'], $category_slug, $resource_slug );
            return is_array( $resource ) ? self::renderPublicResource( $type, $resource ) : null;
        }

        return self::renderPublicArchive( $type, $category_slug, $query );
    }

    private static function renderLandingPage(): ?string {
        $types = self::typeOptions();
        if ( $types === [] ) {
            return null;
        }
        $content = '<section class="metis-resources-public"><div class="metis-resources-public__hero"><p class="metis-resources-public__eyebrow">Resources</p><h1>Find trusted resources</h1><p>Browse curated organizations, guides, and support tools by category.</p></div><div class="metis-resources-public__cards">';
        foreach ( $types as $type ) {
            $url = \metis_home_url( '/resources/' . rawurlencode( (string) ( $type['slug'] ?? '' ) ) . '/' );
            $content .= '<article class="metis-resources-public__card"><h2><a href="' . \metis_escape_attr( $url ) . '">' . \metis_escape_html( (string) ( $type['name'] ?? '' ) ) . '</a></h2></article>';
        }
        $content .= '</div></section>' . self::publicCssTag();

        return WebsiteRenderer::renderPublicDocument( 'Resources', $content, [
            'path' => '/resources/',
            'slug' => 'resources',
            'content_type' => 'resources',
        ] );
    }

    private static function renderPublicArchive( array $type, string $category_slug, array $query ): string {
        $category = $category_slug !== '' ? self::publicCategoryBySlug( (int) $type['id'], $category_slug ) : null;
        $rows = self::publicResources( (int) $type['id'], $category ? (int) $category['id'] : 0, $query );
        $categories = self::publicCategoriesByType( (int) $type['id'] );
        $tags = self::tagOptions( (int) $type['id'] );
        $selected_tag = \metis_slug_clean( (string) ( $query['tag'] ?? '' ) );
        $search = trim( (string) ( $query['q'] ?? '' ) );
        $title = $category ? (string) $category['name'] : (string) $type['name'];
        $intro_html = $category ? (string) ( $category['intro_html'] ?? '' ) : (string) ( $type['intro_html'] ?? '' );

        $content = self::publicCssTag();
        $content .= '<section class="metis-resources-public"><div class="metis-resources-public__hero"><p class="metis-resources-public__eyebrow">Resources</p><h1>' . \metis_escape_html( $title ) . '</h1>';
        if ( trim( $intro_html ) !== '' ) {
            $content .= '<div class="metis-resources-public__intro">' . WebsiteRenderer::sanitizePublicRichText( $intro_html ) . '</div>';
        }
        $content .= '</div>';
        if ( $categories !== [] ) {
            $content .= '<nav class="metis-resources-public__category-nav" aria-label="Resource categories">';
            $all_url = \metis_home_url( '/resources/' . rawurlencode( (string) ( $type['slug'] ?? '' ) ) . '/' );
            $content .= '<a class="metis-resources-public__chip' . ( $category ? '' : ' is-active' ) . '" href="' . \metis_escape_attr( $all_url ) . '">All</a>';
            foreach ( $categories as $category_row ) {
                $category_url = \metis_home_url( '/resources/' . rawurlencode( (string) ( $type['slug'] ?? '' ) ) . '/' . rawurlencode( (string) ( $category_row['slug'] ?? '' ) ) . '/' );
                $is_active = $category && (int) ( $category['id'] ?? 0 ) === (int) ( $category_row['id'] ?? 0 );
                $content .= '<a class="metis-resources-public__chip' . ( $is_active ? ' is-active' : '' ) . '" href="' . \metis_escape_attr( $category_url ) . '">' . \metis_escape_html( (string) ( $category_row['name'] ?? '' ) ) . '</a>';
            }
            $content .= '</nav>';
        }
        $content .= '<form class="metis-resources-public__filters" method="get"><input class="metis-input" type="search" name="q" value="' . \metis_escape_attr( $search ) . '" placeholder="Search resources">';
        if ( $tags !== [] ) {
            $content .= '<select class="metis-select" name="tag"><option value="">All tags</option>';
            foreach ( $tags as $tag ) {
                $slug = (string) ( $tag['slug'] ?? '' );
                $content .= '<option value="' . \metis_escape_attr( $slug ) . '"' . ( $selected_tag === $slug ? ' selected' : '' ) . '>' . \metis_escape_html( (string) ( $tag['name'] ?? '' ) ) . '</option>';
            }
            $content .= '</select>';
        }
        $content .= '<button class="metis-btn" type="submit">Filter</button></form>';
        $content .= '<div class="metis-resources-public__cards">';
        foreach ( $rows as $row ) {
            $url = \metis_home_url( '/resources/' . rawurlencode( (string) $type['slug'] ) . '/' . rawurlencode( (string) ( $row['primary_category_slug'] ?? 'general' ) ) . '/' . rawurlencode( (string) ( $row['slug'] ?? '' ) ) . '/' );
            $content .= '<article class="metis-resources-public__card">';
            if ( ! empty( $row['logo_url'] ) ) {
                $content .= '<div class="metis-resources-public__logo"><img src="' . \metis_escape_attr( (string) $row['logo_url'] ) . '" alt=""></div>';
            }
            $content .= '<h2><a href="' . \metis_escape_attr( $url ) . '">' . \metis_escape_html( (string) ( $row['title'] ?? '' ) ) . '</a></h2>';
            if ( ! empty( $row['organization_name'] ) ) {
                $content .= '<p class="metis-resources-public__meta">' . \metis_escape_html( (string) $row['organization_name'] ) . '</p>';
            }
            if ( ! empty( $row['summary'] ) ) {
                $content .= '<p>' . \metis_escape_html( (string) $row['summary'] ) . '</p>';
            }
            $content .= '</article>';
        }
        if ( $rows === [] ) {
            $content .= '<div class="metis-resources-public__empty">No resources matched this filter yet.</div>';
        }
        $content .= '</div></section>';

        return WebsiteRenderer::renderPublicDocument( $title, $content, [
            'path' => '/resources/' . trim( (string) $type['slug'], '/' ) . ( $category ? '/' . trim( (string) $category['slug'], '/' ) : '' ) . '/',
            'slug' => trim( (string) $type['slug'], '/' ),
            'content_type' => 'resources_archive',
        ] );
    }

    private static function renderPublicResource( array $type, array $resource ): string {
        $title = (string) ( $resource['title'] ?? 'Resource' );
        $content = self::publicCssTag();
        $content .= '<article class="metis-resources-public metis-resources-public--detail"><div class="metis-resources-public__hero"><p class="metis-resources-public__eyebrow">' . \metis_escape_html( (string) ( $type['name'] ?? 'Resources' ) ) . '</p><h1>' . \metis_escape_html( $title ) . '</h1>';
        if ( ! empty( $resource['summary'] ) ) {
            $content .= '<p>' . \metis_escape_html( (string) $resource['summary'] ) . '</p>';
        }
        $content .= '</div><div class="metis-resources-public__detail-grid"><section class="metis-resources-public__body">' . WebsiteRenderer::sanitizePublicRichText( (string) ( $resource['description_html'] ?? '' ) ) . '</section><aside class="metis-resources-public__sidebar">';
        foreach ( [
            'Organization' => (string) ( $resource['organization_name'] ?? '' ),
            'Website' => (string) ( $resource['website_url'] ?? '' ),
            'Phone' => (string) ( $resource['phone'] ?? '' ),
            'Email' => (string) ( $resource['email'] ?? '' ),
            'Location' => trim( implode( ', ', array_filter( [ (string) ( $resource['city'] ?? '' ), (string) ( $resource['state_code'] ?? '' ), (string) ( $resource['postal_code'] ?? '' ) ] ) ) ),
            'County' => (string) ( $resource['county'] ?? '' ),
            'Service Radius' => (string) ( $resource['service_radius'] ?? '' ),
        ] as $label => $value ) {
            if ( trim( $value ) === '' ) {
                continue;
            }
            $content .= '<div class="metis-resources-public__fact"><strong>' . \metis_escape_html( $label ) . '</strong><span>' . ( $label === 'Website' ? '<a href="' . \metis_escape_attr( $value ) . '" target="_blank" rel="noopener">' . \metis_escape_html( $value ) . '</a>' : \metis_escape_html( $value ) ) . '</span></div>';
        }
        $attachments = self::normalizeAttachmentList( (string) ( $resource['attachments_json'] ?? '[]' ) );
        if ( $attachments !== [] ) {
            $content .= '<div class="metis-resources-public__fact"><strong>Downloads</strong><div class="metis-resources-public__downloads">';
            foreach ( $attachments as $item ) {
                $url = trim( (string) ( $item['url'] ?? '' ) );
                if ( $url === '' ) {
                    continue;
                }
                $content .= '<a href="' . \metis_escape_attr( $url ) . '" target="_blank" rel="noopener">' . \metis_escape_html( (string) ( $item['name'] ?? 'Download file' ) ) . '</a>';
            }
            $content .= '</div></div>';
        }
        $content .= '</aside></div></article>';

        return WebsiteRenderer::renderPublicDocument( $title, $content, [
            'path' => '/resources/' . trim( (string) ( $type['slug'] ?? '' ), '/' ) . '/' . trim( (string) ( $resource['primary_category_slug'] ?? 'general' ), '/' ) . '/' . trim( (string) ( $resource['slug'] ?? '' ), '/' ) . '/',
            'slug' => trim( (string) ( $resource['slug'] ?? '' ), '/' ),
            'content_type' => 'resource',
        ] );
    }

    private static function publicTypeBySlug( string $slug ): ?array {
        $row = \metis_db()->fetchOne(
            "SELECT * FROM " . \Metis_Tables::get( 'resource_types' ) . " WHERE slug = %s AND is_active = 1 LIMIT 1",
            [ $slug ]
        );
        return is_array( $row ) ? $row : null;
    }

    private static function publicCategoryBySlug( int $type_id, string $slug ): ?array {
        $row = \metis_db()->fetchOne(
            "SELECT * FROM " . \Metis_Tables::get( 'resource_categories' ) . " WHERE resource_type_id = %d AND slug = %s AND is_active = 1 LIMIT 1",
            [ $type_id, $slug ]
        );
        return is_array( $row ) ? $row : null;
    }

    private static function publicCategoriesByType( int $type_id ): array {
        return \metis_db()->fetchAll(
            "SELECT id, name, slug
             FROM " . \Metis_Tables::get( 'resource_categories' ) . "
             WHERE resource_type_id = %d AND is_active = 1
             ORDER BY sort_order ASC, name ASC",
            [ $type_id ]
        ) ?: [];
    }

    private static function publicResourceByPath( int $type_id, string $category_slug, string $resource_slug ): ?array {
        $resources_table = \Metis_Tables::get( 'resources' );
        $categories_table = \Metis_Tables::get( 'resource_categories' );
        $category_map_table = \Metis_Tables::get( 'resource_category_map' );
        $row = \metis_db()->fetchOne(
            "SELECT r.*, c.slug AS primary_category_slug
             FROM {$resources_table} r
             LEFT JOIN {$categories_table} c ON c.id = r.primary_category_id
             WHERE r.resource_type_id = %d
               AND r.slug = %s
               AND r.status = 'published'
               AND (r.expires_at IS NULL OR r.expires_at > %s)
             LIMIT 1",
            [ $type_id, $resource_slug, \metis_current_time( 'mysql' ) ]
        );
        if ( ! is_array( $row ) ) {
            return null;
        }
        if ( $category_slug !== '' ) {
            $matches_category = (string) ( $row['primary_category_slug'] ?? '' ) === $category_slug;
            if ( ! $matches_category ) {
                $matches_category = (int) \metis_db()->scalar(
                    "SELECT COUNT(*)
                     FROM {$category_map_table} m
                     INNER JOIN {$categories_table} c ON c.id = m.category_id
                     WHERE m.resource_id = %d AND c.slug = %s",
                    [ (int) ( $row['id'] ?? 0 ), $category_slug ]
                ) > 0;
            }
            if ( ! $matches_category ) {
                return null;
            }
        }
        return $row;
    }

    private static function publicResources( int $type_id, int $category_id, array $query ): array {
        $db = \metis_db();
        $resources_table = \Metis_Tables::get( 'resources' );
        $categories_table = \Metis_Tables::get( 'resource_categories' );
        $tags_map = \Metis_Tables::get( 'resource_tag_map' );
        $tags_table = \Metis_Tables::get( 'resource_tags' );
        $where = [ 'r.resource_type_id = %d', "r.status = 'published'", '(r.expires_at IS NULL OR r.expires_at > %s)' ];
        $params = [ $type_id, \metis_current_time( 'mysql' ) ];
        if ( $category_id > 0 ) {
            $resource_categories_table = \Metis_Tables::get( 'resource_category_map' );
            $where[] = "EXISTS (SELECT 1 FROM {$resource_categories_table} cm WHERE cm.resource_id = r.id AND cm.category_id = %d)";
            $params[] = $category_id;
        }
        $search = trim( (string) ( $query['q'] ?? '' ) );
        if ( $search !== '' ) {
            $where[] = '(r.title LIKE %s OR r.organization_name LIKE %s OR r.summary LIKE %s OR r.city LIKE %s OR r.county LIKE %s)';
            $like = '%' . $search . '%';
            array_push( $params, $like, $like, $like, $like, $like );
        }
        $tag_slug = \metis_slug_clean( (string) ( $query['tag'] ?? '' ) );
        if ( $tag_slug !== '' ) {
            $where[] = "EXISTS (SELECT 1 FROM {$tags_map} tm INNER JOIN {$tags_table} t ON t.id = tm.tag_id WHERE tm.resource_id = r.id AND t.slug = %s)";
            $params[] = $tag_slug;
        }

        return $db->fetchAll(
            "SELECT r.*, c.slug AS primary_category_slug
             FROM {$resources_table} r
             LEFT JOIN {$categories_table} c ON c.id = r.primary_category_id
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY r.is_featured DESC, r.sort_order ASC, r.title ASC",
            $params
        ) ?: [];
    }

    private static function publicCssTag(): string {
        static $printed = false;
        if ( $printed ) {
            return '';
        }
        $printed = true;
        return '<style>'
            . '.metis-resources-public{display:grid;gap:24px}.metis-resources-public__hero{padding:28px;border:1px solid #d8def2;border-radius:28px;background:linear-gradient(180deg,#fbfcff 0%,#f4f7ff 100%)}.metis-resources-public__eyebrow{margin:0 0 10px;font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#60708d;font-weight:700}'
            . '.metis-resources-public__cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.metis-resources-public__card{padding:20px;border:1px solid #d8def2;border-radius:24px;background:#fff;box-shadow:0 18px 40px rgba(34,52,102,.06)}.metis-resources-public__card h2{margin:0 0 10px;font-size:1.2rem}.metis-resources-public__card h2 a{text-decoration:none;color:inherit}'
            . '.metis-resources-public__filters{display:flex;gap:12px;flex-wrap:wrap}.metis-resources-public__meta{margin:0 0 8px;color:#53627e;font-weight:600}.metis-resources-public__logo img{max-width:72px;max-height:72px;display:block;margin-bottom:12px}'
            . '.metis-resources-public__detail-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:24px}.metis-resources-public__body,.metis-resources-public__sidebar{padding:22px;border:1px solid #d8def2;border-radius:24px;background:#fff}.metis-resources-public__sidebar{display:grid;gap:14px;align-content:start}'
            . '.metis-resources-public__fact{display:grid;gap:6px}.metis-resources-public__downloads{display:grid;gap:8px}.metis-resources-public__empty{padding:18px;border:1px dashed #c7d1f0;border-radius:20px;background:#fff}'
            . '@media (max-width:980px){.metis-resources-public__cards,.metis-resources-public__detail-grid{grid-template-columns:1fr 1fr}}@media (max-width:720px){.metis-resources-public__cards,.metis-resources-public__detail-grid{grid-template-columns:1fr}}'
            . '</style>';
    }

    private static function categoryNamesByResourceId(): array {
        $rows = \metis_db()->fetchAll(
            "SELECT m.resource_id, c.name
             FROM " . \Metis_Tables::get( 'resource_category_map' ) . " m
             INNER JOIN " . \Metis_Tables::get( 'resource_categories' ) . " c ON c.id = m.category_id
             ORDER BY c.sort_order ASC, c.name ASC"
        ) ?: [];
        $map = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row['resource_id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $map[ $id ][] = (string) ( $row['name'] ?? '' );
        }
        return $map;
    }

    private static function categoryIdsMapByResourceId(): array {
        $rows = \metis_db()->fetchAll(
            "SELECT resource_id, category_id
             FROM " . \Metis_Tables::get( 'resource_category_map' ) . "
             ORDER BY resource_id ASC, is_primary DESC, category_id ASC"
        ) ?: [];
        $map = [];
        foreach ( $rows as $row ) {
            $resource_id = (int) ( $row['resource_id'] ?? 0 );
            $category_id = (int) ( $row['category_id'] ?? 0 );
            if ( $resource_id < 1 || $category_id < 1 ) {
                continue;
            }
            $map[ $resource_id ][] = $category_id;
        }
        return $map;
    }

    private static function tagNamesByResourceId(): array {
        $rows = \metis_db()->fetchAll(
            "SELECT m.resource_id, t.name
             FROM " . \Metis_Tables::get( 'resource_tag_map' ) . " m
             INNER JOIN " . \Metis_Tables::get( 'resource_tags' ) . " t ON t.id = m.tag_id
             ORDER BY t.sort_order ASC, t.name ASC"
        ) ?: [];
        $map = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row['resource_id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $map[ $id ][] = (string) ( $row['name'] ?? '' );
        }
        return $map;
    }

    private static function tagIdsMapByResourceId(): array {
        $rows = \metis_db()->fetchAll(
            "SELECT resource_id, tag_id
             FROM " . \Metis_Tables::get( 'resource_tag_map' ) . "
             ORDER BY resource_id ASC, tag_id ASC"
        ) ?: [];
        $map = [];
        foreach ( $rows as $row ) {
            $resource_id = (int) ( $row['resource_id'] ?? 0 );
            $tag_id = (int) ( $row['tag_id'] ?? 0 );
            if ( $resource_id < 1 || $tag_id < 1 ) {
                continue;
            }
            $map[ $resource_id ][] = $tag_id;
        }
        return $map;
    }

    private static function uniqueSlug( string $table, string $candidate, int $exclude_id = 0 ): string {
        $candidate = \metis_slug_clean( $candidate );
        if ( $candidate === '' ) {
            return '';
        }
        $db = \metis_db();
        $slug = $candidate;
        $suffix = 2;
        while ( (int) $db->scalar( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1", [ $slug, $exclude_id ] ) > 0 ) {
            $slug = $candidate . '-' . $suffix;
            ++$suffix;
        }
        return $slug;
    }

    private static function uniqueScopedSlug( string $table, string $scope_column, int $scope_id, string $candidate, int $exclude_id = 0 ): string {
        $candidate = \metis_slug_clean( $candidate );
        if ( $candidate === '' ) {
            return '';
        }
        $db = \metis_db();
        $slug = $candidate;
        $suffix = 2;
        while ( (int) $db->scalar( "SELECT id FROM {$table} WHERE {$scope_column} = %d AND slug = %s AND id <> %d LIMIT 1", [ $scope_id, $slug, $exclude_id ] ) > 0 ) {
            $slug = $candidate . '-' . $suffix;
            ++$suffix;
        }
        return $slug;
    }

    private static function handleUpload( $file, string $folder, string $category ): array {
        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            return [ 'ok' => false ];
        }
        $stored = \metis_handle_upload( $file, [
            'folder_path' => $folder,
            'category_key' => $category,
        ] );
        if ( ! is_array( $stored ) || ! empty( $stored['error'] ) ) {
            return [ 'ok' => false ];
        }
        return [
            'ok' => true,
            'token' => (string) ( $stored['token'] ?? '' ),
            'url' => (string) ( $stored['url'] ?? '' ),
            'file_name' => (string) ( $stored['file_name'] ?? $stored['name'] ?? '' ),
        ];
    }

    private static function normalizeFileArray( $files ): array {
        if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
            return [];
        }
        if ( ! is_array( $files['name'] ) ) {
            return [ $files ];
        }
        $normalized = [];
        foreach ( array_keys( $files['name'] ) as $index ) {
            $normalized[] = [
                'name' => $files['name'][ $index ] ?? '',
                'type' => $files['type'][ $index ] ?? '',
                'tmp_name' => $files['tmp_name'][ $index ] ?? '',
                'error' => $files['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][ $index ] ?? 0,
            ];
        }
        return $normalized;
    }

    private static function normalizeAttachmentList( string $json ): array {
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? array_values( array_filter( $decoded, static fn ( $row ): bool => is_array( $row ) ) ) : [];
    }

    private static function nullable( string $value ): ?string {
        $value = trim( $value );
        return $value !== '' ? $value : null;
    }

    private static function nullableUrl( string $value ): ?string {
        $value = trim( $value );
        return $value !== '' ? \metis_url_clean( $value ) : null;
    }

    private static function nullableEmail( string $value ): ?string {
        $value = trim( strtolower( $value ) );
        return \metis_email_is_valid( $value ) ? $value : null;
    }

    private static function normalizeDateTime( string $value ): ?string {
        $value = trim( str_replace( 'T', ' ', $value ) );
        if ( $value === '' ) {
            return null;
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
            $value .= ':00';
        }
        return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) === 1 ? $value : null;
    }
}
