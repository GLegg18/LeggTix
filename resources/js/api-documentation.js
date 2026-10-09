"use strict";

window.addEventListener("DOMContentLoaded", function () {
    window.ui = SwaggerUIBundle({
        url: "/docs/api.json",
        dom_id: "#swagger-ui",
        layout: "BaseLayout",
        deepLinking: true,
        defaultModelsExpandDepth: 0,
        displayRequestDuration: true,
        persistAuthorization: false,
        queryConfigEnabled: false,
        validatorUrl: null,
        withCredentials: false,
        requestInterceptor: function (request) {
            const target = new URL(request.url, window.location.href);
            if (target.origin !== window.location.origin ||
                (target.pathname !== "/docs/api.json" && !target.pathname.startsWith("/api/"))) {
                throw new Error("The API reference only sends requests to this installation.");
            }
            request.credentials = "omit";
            request.redirect = "error";
            return request;
        }
    });
});
