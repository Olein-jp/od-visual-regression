// 製品APIの契約。変更後は node scripts/generate-contract-types.mjs でSchemaと同期する。
export const COMMUNICATION_SCHEMA_VERSION = 1 as const;
export const SETTINGS_JSON_VERSION = 1 as const;
export const ENVIRONMENT_JSON_VERSION = 1 as const;
export const SNAPSHOT_METADATA_VERSION = 1 as const;
export const DATABASE_VERSION = 1 as const;
export type BaselineRequest = {
    schema_version: 1;
    run_id: number;
};
export type CompleteRequest = {
    schema_version: 1;
    runner_execution_id: string;
    versions: {
        runner: string;
        playwright: string;
        chromium: string;
    };
    outcome: "finished" | "failed";
    error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
    error_message: string | null;
};
export type ConnectionTestRequest = {
    schema_version: 1;
};
export type ConnectionTestResponse = {
    schema_version: 1;
    item: {
        checks: {
            settings: "passed" | "failed";
            storage: "passed" | "failed";
            dispatcher: "passed" | "failed";
        };
        checked_at: string;
        code: string;
        message: string;
    };
};
export type DeviceCreateRequest = {
    schema_version: 1;
    name: string;
    slug: string;
    viewport_width: number;
    viewport_height: number;
    device_scale_factor: number;
    is_mobile: boolean;
    has_touch: boolean;
    user_agent: string;
    enabled: boolean;
    sort_order: number;
};
export type DeviceListResponse = {
    schema_version: 1;
    items: Array<{
        id: number;
        name: string;
        slug: string;
        viewport_width: number;
        viewport_height: number;
        device_scale_factor: number;
        is_mobile: boolean;
        has_touch: boolean;
        user_agent?: string;
        enabled: boolean;
        sort_order: number;
        using_suites: Array<{
            id: number;
            name: string;
        }>;
    }>;
};
export type DevicePatchRequest = {
    schema_version: 1;
    name?: string;
    slug?: string;
    viewport_width?: number;
    viewport_height?: number;
    device_scale_factor?: number;
    is_mobile?: boolean;
    has_touch?: boolean;
    user_agent?: string;
    enabled?: boolean;
    sort_order?: number;
};
export type DeviceResponse = {
    schema_version: 1;
    item: {
        id: number;
        name: string;
        slug: string;
        viewport_width: number;
        viewport_height: number;
        device_scale_factor: number;
        is_mobile: boolean;
        has_touch: boolean;
        user_agent?: string;
        enabled: boolean;
        sort_order: number;
        using_suites: Array<{
            id: number;
            name: string;
        }>;
    };
};
export type DispatchConnectionTestRequest = {
    schema_version: 1;
    site_id: string;
    callback_base: string;
};
export type DispatchRequest = {
    schema_version: 1;
    site_id: string;
    run_uuid: string;
    callback_base: string;
    runner_token: string;
};
export type DispatchResponse = {
    schema_version: 1;
    site_id: string;
    run_uuid: string;
    status: "accepted" | "started";
    runner_execution_id: string | null;
};
export type Error = {
    schema_version: 1;
    code: string;
    message: string;
    data: {
        status: number;
        retryable: boolean;
        request_id: string;
        using_suites?: Array<{
            id: number;
            name: string;
        }>;
    };
};
export type ProgressRequest = {
    schema_version: 1;
    runner_execution_id: string;
    versions: {
        runner: string;
        playwright: string;
        chromium: string;
    };
};
export type RunCreateRequest = {
    schema_version: 1;
    baseline_mode: "pinned" | "previous" | "specific";
    reference_run_id?: number;
};
export type RunCreateResponse = {
    schema_version: 1;
    item: {
        run_uuid: string;
        status: "queued";
        deadline_at: string;
    };
};
export type RunEnvironment = {
    environment_version: 1;
    wordpress: string;
    php: string;
    theme: {
        name: string;
        version: string | null;
    };
    parent_theme: {
        name: string;
        version: string | null;
    } | null;
    plugins: Array<{
        id: string;
        name: string;
        version: string | null;
    }>;
    mu_plugins: Array<{
        id: string;
        name: string;
        version: string | null;
    }>;
    locale: string;
    site_url: string;
    runner: string | null;
    playwright: string | null;
    chromium: string | null;
};
export type RunListResponse = {
    schema_version: 1;
    items: Array<{
        id: number;
        run_uuid: string;
        suite_id: number;
        suite_name: string;
        status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
        created_at: string;
        deadline_at: string;
        completed_at: string | null;
        total_snapshots: number;
        completed_snapshots: number;
        error_snapshots: number;
        pending_snapshots: number;
        error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
        error_message: string | null;
        reference_run_id: number | null;
        environment: {
            environment_version: 1;
            wordpress: string;
            php: string;
            theme: {
                name: string;
                version: string | null;
            };
            parent_theme: {
                name: string;
                version: string | null;
            } | null;
            plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            mu_plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            locale: string;
            site_url: string;
            runner: string | null;
            playwright: string | null;
            chromium: string | null;
        };
        reference_environment: {
            environment_version: 1;
            wordpress: string;
            php: string;
            theme: {
                name: string;
                version: string | null;
            };
            parent_theme: {
                name: string;
                version: string | null;
            } | null;
            plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            mu_plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            locale: string;
            site_url: string;
            runner: string | null;
            playwright: string | null;
            chromium: string | null;
        } | null;
        targets: Array<{
            id: number;
            url: string;
            label: string;
            object_id: number | null;
            post_type: string;
        }>;
        devices: Array<{
            id: number;
            name: string;
            slug: string;
            viewport_width: number;
            viewport_height: number;
            device_scale_factor: number;
            is_mobile: boolean;
            has_touch: boolean;
            user_agent?: string;
        }>;
        settings: {
            navigation_timeout_ms: number;
            image_timeout_ms: number;
            lazy_load: boolean;
            concurrency: number;
            pixel_threshold: number;
            review_threshold: number;
            changed_threshold: number;
            ignore_selectors: Array<string>;
            settings_version: 1;
        };
        protection: {
            reasons: Array<"pinned" | "active" | "referenced">;
            referenced_by: Array<{
                id: number;
                run_uuid: string;
                status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
                created_at: string;
            }>;
        };
        deletion_error: string | null;
    }>;
};
export type RunManifest = {
    schema_version: 1;
    run: {
        uuid: string;
        suite_id: number;
        status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
        created_at: string;
        deadline_at: string;
        runner_execution_id: string;
        snapshot_states: Array<{
            snapshot_id: number;
            target_id: number;
            device_id: number;
            status: "PENDING" | "CAPTURED" | "NO_BASELINE" | "UNCHANGED" | "REVIEW" | "CHANGED" | "ERROR";
        }>;
    };
    suite: {
        id: number;
        name: string;
    };
    targets: Array<{
        id: number;
        url: string;
        label: string;
        object_id: number | null;
        post_type: string;
    }>;
    devices: Array<{
        id: number;
        name: string;
        slug: string;
        viewport_width: number;
        viewport_height: number;
        device_scale_factor: number;
        is_mobile: boolean;
        has_touch: boolean;
        user_agent?: string;
    }>;
    settings: {
        navigation_timeout_ms: number;
        image_timeout_ms: number;
        lazy_load: boolean;
        concurrency: number;
        pixel_threshold: number;
        review_threshold: number;
        changed_threshold: number;
        ignore_selectors: Array<string>;
        settings_version: 1;
    };
    reference: {
        mode: "pinned" | "previous" | "specific";
        run_id: number | null;
        snapshots: Array<{
            target_id: number;
            device_id: number;
            baseline_snapshot_id: number | null;
            reason: "no_reference" | "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
        }>;
        versions: {
            runner: string;
            playwright: string;
            chromium: string;
        } | null;
    };
    allowed_origins: Array<string>;
};
export type RunResponse = {
    schema_version: 1;
    item: {
        id: number;
        run_uuid: string;
        suite_id: number;
        suite_name: string;
        status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
        created_at: string;
        deadline_at: string;
        completed_at: string | null;
        total_snapshots: number;
        completed_snapshots: number;
        error_snapshots: number;
        pending_snapshots: number;
        error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
        error_message: string | null;
        reference_run_id: number | null;
        environment: {
            environment_version: 1;
            wordpress: string;
            php: string;
            theme: {
                name: string;
                version: string | null;
            };
            parent_theme: {
                name: string;
                version: string | null;
            } | null;
            plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            mu_plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            locale: string;
            site_url: string;
            runner: string | null;
            playwright: string | null;
            chromium: string | null;
        };
        reference_environment: {
            environment_version: 1;
            wordpress: string;
            php: string;
            theme: {
                name: string;
                version: string | null;
            };
            parent_theme: {
                name: string;
                version: string | null;
            } | null;
            plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            mu_plugins: Array<{
                id: string;
                name: string;
                version: string | null;
            }>;
            locale: string;
            site_url: string;
            runner: string | null;
            playwright: string | null;
            chromium: string | null;
        } | null;
        targets: Array<{
            id: number;
            url: string;
            label: string;
            object_id: number | null;
            post_type: string;
        }>;
        devices: Array<{
            id: number;
            name: string;
            slug: string;
            viewport_width: number;
            viewport_height: number;
            device_scale_factor: number;
            is_mobile: boolean;
            has_touch: boolean;
            user_agent?: string;
        }>;
        settings: {
            navigation_timeout_ms: number;
            image_timeout_ms: number;
            lazy_load: boolean;
            concurrency: number;
            pixel_threshold: number;
            review_threshold: number;
            changed_threshold: number;
            ignore_selectors: Array<string>;
            settings_version: 1;
        };
        protection: {
            reasons: Array<"pinned" | "active" | "referenced">;
            referenced_by: Array<{
                id: number;
                run_uuid: string;
                status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
                created_at: string;
            }>;
        };
        deletion_error: string | null;
    };
};
export type RunState = {
    schema_version: 1;
    run_uuid: string;
    status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
    total_snapshots: number;
    completed_snapshots: number;
    error_snapshots: number;
    pending_snapshots: number;
    completed_at: string | null;
    error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
};
export type RunnerCredentials = {
    schema_version: 1;
    http_auth: {
        origin: string;
        username: string;
        password: string;
    } | null;
};
export type SettingsPatchRequest = {
    schema_version: 1;
    dispatcher_url?: string;
    site_id?: string;
    retention?: {
        mode: "all" | "last";
        count: number | null;
    };
    queued_timeout_seconds?: number;
    run_timeout_seconds?: number;
};
export type SettingsResponse = {
    schema_version: 1;
    item: {
        dispatcher_url: string;
        site_id: string;
        retention: {
            mode: "all" | "last";
            count: number | null;
        };
        queued_timeout_seconds: number;
        run_timeout_seconds: number;
        dispatcher_secret_configured: boolean;
        http_auth_configured: boolean;
        http_auth_origin: string | null;
        retained_runs: number;
        storage_bytes: number | null;
        deletion_failures: number;
    };
};
export type SnapshotListResponse = {
    schema_version: 1;
    items: Array<{
        target_id: number;
        device_id: number;
        status: "PENDING" | "CAPTURED" | "NO_BASELINE" | "UNCHANGED" | "REVIEW" | "CHANGED" | "ERROR";
        width: number | null;
        height: number | null;
        baseline_width: number | null;
        baseline_height: number | null;
        dimension_changed: boolean;
        diff_pixels: number | null;
        total_pixels: number | null;
        diff_ratio: number | null;
        duration_ms: number;
        http_status: number | null;
        error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
        error_message: string | null;
        no_baseline_reason: "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
        snapshot_id: number;
        has_current_image: boolean;
        has_diff_image: boolean;
        has_baseline_image: boolean;
    }>;
};
export type SnapshotMetadata = {
    metadata_version: 1;
    target: {
        id: number;
        url: string;
        label: string;
        object_id: number | null;
        post_type: string;
    };
    device: {
        id: number;
        name: string;
        slug: string;
        viewport_width: number;
        viewport_height: number;
        device_scale_factor: number;
        is_mobile: boolean;
        has_touch: boolean;
        user_agent?: string;
    };
    reference: {
        target_id: number;
        device_id: number;
        baseline_snapshot_id: number | null;
        reason: "no_reference" | "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
    };
    image_sha256: string | null;
    diff_sha256: string | null;
    result_digest: string | null;
    result: {
        schema_version: 1;
        target_id: number;
        device_id: number;
        status: "CAPTURED" | "NO_BASELINE" | "UNCHANGED" | "REVIEW" | "CHANGED" | "ERROR";
        width: number | null;
        height: number | null;
        baseline_width: number | null;
        baseline_height: number | null;
        dimension_changed: boolean;
        diff_pixels: number | null;
        total_pixels: number | null;
        diff_ratio: number | null;
        duration_ms: number;
        http_status: number | null;
        error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
        error_message: string | null;
        no_baseline_reason: "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
    } | null;
};
export type SnapshotResponse = {
    schema_version: 1;
    item: {
        target_id: number;
        device_id: number;
        status: "PENDING" | "CAPTURED" | "NO_BASELINE" | "UNCHANGED" | "REVIEW" | "CHANGED" | "ERROR";
        width: number | null;
        height: number | null;
        baseline_width: number | null;
        baseline_height: number | null;
        dimension_changed: boolean;
        diff_pixels: number | null;
        total_pixels: number | null;
        diff_ratio: number | null;
        duration_ms: number;
        http_status: number | null;
        error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
        error_message: string | null;
        no_baseline_reason: "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
        snapshot_id: number;
        has_current_image: boolean;
        has_diff_image: boolean;
        has_baseline_image: boolean;
    };
};
export type SnapshotResult = {
    schema_version: 1;
    target_id: number;
    device_id: number;
    status: "CAPTURED" | "NO_BASELINE" | "UNCHANGED" | "REVIEW" | "CHANGED" | "ERROR";
    width: number | null;
    height: number | null;
    baseline_width: number | null;
    baseline_height: number | null;
    dimension_changed: boolean;
    diff_pixels: number | null;
    total_pixels: number | null;
    diff_ratio: number | null;
    duration_ms: number;
    http_status: number | null;
    error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
    error_message: string | null;
    no_baseline_reason: "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
};
export type SnapshotUploadResponse = {
    schema_version: 1;
    snapshot_id: number;
    status: "CAPTURED" | "NO_BASELINE" | "UNCHANGED" | "REVIEW" | "CHANGED" | "ERROR";
    replayed: boolean;
};
export type StoredRunEnvironment = {
    environment_version: 1;
    wordpress: string;
    php: string;
    theme: {
        name: string;
        version: string | null;
    };
    parent_theme: {
        name: string;
        version: string | null;
    } | null;
    plugins: Array<{
        id: string;
        name: string;
        version: string | null;
    }>;
    mu_plugins: Array<{
        id: string;
        name: string;
        version: string | null;
    }>;
    locale: string;
    site_url: string;
    runner: string | null;
    playwright: string | null;
    chromium: string | null;
    completion: {
        digest: string;
        request: {
            schema_version: 1;
            runner_execution_id: string;
            versions: {
                runner: string;
                playwright: string;
                chromium: string;
            };
            outcome: "finished" | "failed";
            error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
            error_message: string | null;
        };
        response: {
            schema_version: 1;
            run_uuid: string;
            status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
            total_snapshots: number;
            completed_snapshots: number;
            error_snapshots: number;
            pending_snapshots: number;
            completed_at: string | null;
            error_code: "SNAPSHOT_FAILED" | "NAVIGATION_FAILED" | "HTTP_ERROR" | "CAPTURE_FAILED" | "CONTEXT_CLOSE_FAILED" | "RUN_ABORTED" | "RUN_DEADLINE_EXCEEDED" | "DISPATCH_TIMEOUT" | null;
        };
    } | null;
};
export type StoredRunManifest = {
    schema_version: 1;
    suite: {
        id: number;
        name: string;
    };
    targets: Array<{
        id: number;
        url: string;
        label: string;
        object_id: number | null;
        post_type: string;
    }>;
    devices: Array<{
        id: number;
        name: string;
        slug: string;
        viewport_width: number;
        viewport_height: number;
        device_scale_factor: number;
        is_mobile: boolean;
        has_touch: boolean;
        user_agent?: string;
    }>;
    settings: {
        navigation_timeout_ms: number;
        image_timeout_ms: number;
        lazy_load: boolean;
        concurrency: number;
        pixel_threshold: number;
        review_threshold: number;
        changed_threshold: number;
        ignore_selectors: Array<string>;
        settings_version: 1;
    };
    reference: {
        mode: "pinned" | "previous" | "specific";
        run_id: number | null;
        snapshots: Array<{
            target_id: number;
            device_id: number;
            baseline_snapshot_id: number | null;
            reason: "no_reference" | "new_target" | "new_device" | "incompatible" | "missing" | "corrupt" | null;
        }>;
        versions: {
            runner: string;
            playwright: string;
            chromium: string;
        } | null;
    };
    allowed_origins: Array<string>;
    queued_deadline_at: string;
    http_auth_origin: string | null;
};
export type SuiteCreateRequest = {
    schema_version: 1;
    name: string;
    settings: {
        navigation_timeout_ms: number;
        image_timeout_ms: number;
        lazy_load: boolean;
        concurrency: number;
        pixel_threshold: number;
        review_threshold: number;
        changed_threshold: number;
        ignore_selectors: Array<string>;
        settings_version: 1;
    };
    device_ids: Array<number>;
    allowed_origins: Array<string>;
    retention: {
        mode: "all" | "last";
        count: number | null;
    };
};
export type SuiteListResponse = {
    schema_version: 1;
    items: Array<{
        id: number;
        uuid: string;
        name: string;
        status: "active" | "archived";
        settings: {
            navigation_timeout_ms: number;
            image_timeout_ms: number;
            lazy_load: boolean;
            concurrency: number;
            pixel_threshold: number;
            review_threshold: number;
            changed_threshold: number;
            ignore_selectors: Array<string>;
            settings_version: 1;
        };
        device_ids: Array<number>;
        allowed_origins: Array<string>;
        retention: {
            mode: "all" | "last";
            count: number | null;
        };
        baseline_run_id: number | null;
        target_count: number;
        device_count: number;
        latest_run: {
            id: number;
            run_uuid: string;
            status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
            created_at: string;
        } | null;
    }>;
};
export type SuitePatchRequest = {
    schema_version: 1;
    name?: string;
    settings?: {
        navigation_timeout_ms: number;
        image_timeout_ms: number;
        lazy_load: boolean;
        concurrency: number;
        pixel_threshold: number;
        review_threshold: number;
        changed_threshold: number;
        ignore_selectors: Array<string>;
        settings_version: 1;
    };
    device_ids?: Array<number>;
    allowed_origins?: Array<string>;
    retention?: {
        mode: "all" | "last";
        count: number | null;
    };
};
export type SuiteResponse = {
    schema_version: 1;
    item: {
        id: number;
        uuid: string;
        name: string;
        status: "active" | "archived";
        settings: {
            navigation_timeout_ms: number;
            image_timeout_ms: number;
            lazy_load: boolean;
            concurrency: number;
            pixel_threshold: number;
            review_threshold: number;
            changed_threshold: number;
            ignore_selectors: Array<string>;
            settings_version: 1;
        };
        device_ids: Array<number>;
        allowed_origins: Array<string>;
        retention: {
            mode: "all" | "last";
            count: number | null;
        };
        baseline_run_id: number | null;
        target_count: number;
        device_count: number;
        latest_run: {
            id: number;
            run_uuid: string;
            status: "queued" | "running" | "complete" | "partial" | "failed" | "deleting";
            created_at: string;
        } | null;
    };
};
export type TargetCreateRequest = {
    schema_version: 1;
    url: string;
    label: string;
    object_id: number | null;
    post_type: string;
    enabled: boolean;
    sort_order: number;
};
export type TargetListResponse = {
    schema_version: 1;
    items: Array<{
        id: number;
        url: string;
        label: string;
        object_id: number | null;
        post_type: string;
        suite_id: number;
        enabled: boolean;
        sort_order: number;
    }>;
};
export type TargetPatchRequest = {
    schema_version: 1;
    url?: string;
    label?: string;
    object_id?: number | null;
    post_type?: string;
    enabled?: boolean;
    sort_order?: number;
};
export type TargetResponse = {
    schema_version: 1;
    item: {
        id: number;
        url: string;
        label: string;
        object_id: number | null;
        post_type: string;
        suite_id: number;
        enabled: boolean;
        sort_order: number;
    };
};
