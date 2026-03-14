export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(triggerCron(env, "scheduled"));
  },

  async fetch(request, env) {
    if (request.method !== "POST") {
      return new Response("Method Not Allowed", { status: 405 });
    }

    const result = await triggerCron(env, "manual");
    return new Response(JSON.stringify(result.body), {
      status: result.status,
      headers: {
        "content-type": "application/json; charset=UTF-8",
      },
    });
  },
};

async function triggerCron(env, trigger) {
  if (!env.METIS_ORIGIN_URL || !env.METIS_CRON_SECRET) {
    return {
      status: 500,
      body: {
        success: false,
        error: "Worker environment is missing METIS_ORIGIN_URL or METIS_CRON_SECRET.",
      },
    };
  }

  const url = new URL("/system/cron", env.METIS_ORIGIN_URL);
  const requestId = crypto.randomUUID();
  const response = await fetch(url.toString(), {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "x-metis-cron-secret": env.METIS_CRON_SECRET,
      "x-request-id": requestId,
      "user-agent": "metis-cloudflare-cron/1.0",
    },
    body: JSON.stringify({
      trigger,
    }),
  });

  let body;
  try {
    body = await response.json();
  } catch {
    body = {
      success: false,
      error: "Cron endpoint returned a non-JSON response.",
    };
  }

  return {
    status: response.status,
    body,
  };
}
