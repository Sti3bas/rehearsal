export default defineEventHandler(async (event) => {
  const origin = useRuntimeConfig(event).rehearsal.laravelOrigin;
  const request = new Request(new URL("/api/probe/echo", origin), { method: "POST", body: "original" });
  await request.text();
  const result = await fetch(request, { body: "replacement" });

  return result.json();
});
