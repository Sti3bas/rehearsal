export default defineEventHandler(async (event) => {
  const origin = useRuntimeConfig(event).rehearsal.laravelOrigin;
  const request = new Request(new URL("/api/probe/echo", origin), { method: "POST", body: "original" });
  await request.text();
  const streamed = getQuery(event).stream === "1";
  const result = streamed ? await fetch(request, {
    body: new ReadableStream({
      start(controller) {
        controller.enqueue(new TextEncoder().encode("streamed replacement"));
        controller.close();
      },
    }),
    duplex: "half",
  } satisfies RequestInit & { duplex: "half" }) : await fetch(request, { body: "replacement" });

  return result.json();
});
