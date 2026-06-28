APPLICATION_STUCK_TOOLS = [
    {
        "type": "function",
        "function": {
            "name": "find_application",
            "description": "Find application using application_id, applicationId, mobile, order_id, or grn.",
            "parameters": {
                "type": "object",
                "properties": {
                    "search_type": {
                        "type": "string",
                        "enum": ["application_id", "applicationId", "mobile", "order_id", "grn"],
                    },
                    "search_value": {
                        "type": "string",
                    },
                },
                "required": ["search_type", "search_value"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_application_details",
            "description": "Get main application details from user_service_applications.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "string"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_user_details",
            "description": "Get masked user details by user_id.",
            "parameters": {
                "type": "object",
                "properties": {
                    "user_id": {"type": "integer"},
                },
                "required": ["user_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_service_details",
            "description": "Get service and department details.",
            "parameters": {
                "type": "object",
                "properties": {
                    "service_id": {"type": "integer"},
                },
                "required": ["service_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_payment_details",
            "description": "Get payment orders for application.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "string"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_assignment_flow",
            "description": "Get application workflow assignments.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "string"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_workflow_history",
            "description": "Get application workflow history/actions.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "string"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_service_approval_flow",
            "description": "Get expected approval flow for service.",
            "parameters": {
                "type": "object",
                "properties": {
                    "service_id": {"type": "integer"},
                },
                "required": ["service_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_basic_system_checks",
            "description": "Get quick system checks for payment, assignment and history.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "string"},
                },
                "required": ["application_id"],
            },
        },
    },
]