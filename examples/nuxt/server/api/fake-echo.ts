export default defineEventHandler(async (event) => {
  if (event.method === "POST") return { body: await readRawBody(event) };

  const request = new Request(getRequestURL(event), { method: "POST", body: "original" });
  await request.text();
  const result = await fetch(request, { body: "replacement" });

  return result.json();
});
