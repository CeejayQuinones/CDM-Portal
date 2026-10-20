# Admission API boundary

`admissionService.js` uses the existing portal apiClient for the read-only
`GET /admission/me` endpoint. It does not create an Axios instance, persist identity
responses, or send mutation requests. Invalid responses throw so the page presents
a generic error instead of treating unavailable data as an empty application.
