import type { Club, Member } from "./types";

export const club: Club = {
  name: "Kelab Contoh (Demo Club)",
  shortName: "Kelab Contoh",
  regNo: "PPM-012-14-03082019",
  email: "bendahari@npcclub.org.my",
  financialYear: "2026",
};

/** Signed-in member for the member screens. */
export const currentMember: Member = {
  id: 9,
  name: "Muhammad Haziq bin Azman",
  memberNo: "DEMO-0142",
  email: "haziq.azman@example.my",
  phone: "+60 12-345 6789",
};

/** Signed-in admin for the admin screens. */
export const currentAdmin = { name: "Lim Siew Ling", role: "Bendahari (Treasurer)" };

export const members: Member[] = [
  { id: 1, name: "Ahmad Faizal bin Rahman", memberNo: "DEMO-0001", email: "faizal@example.my", phone: "+60 13-210 4455", role: "Pengerusi (Chairman)" },
  { id: 2, name: "Lim Siew Ling", memberNo: "DEMO-0002", email: "siewling@example.my", phone: "+60 16-778 9012", role: "Bendahari (Treasurer)" },
  { id: 3, name: "Priya a/p Subramaniam", memberNo: "DEMO-0003", email: "priya.s@example.my", phone: "+60 17-334 5120", role: "Setiausaha (Secretary)" },
  { id: 4, name: "Wong Kah Hoe", memberNo: "DEMO-0004", email: "kahhoe@example.my", phone: "+60 12-901 2234", role: "Penolong Bendahari" },
  { id: 5, name: "Nurul Aina binti Zulkifli", memberNo: "DEMO-0021", email: "aina.z@example.my", phone: "+60 19-455 7781" },
  { id: 6, name: "Tan Wei Ming", memberNo: "DEMO-0034", email: "weiming.tan@example.my", phone: "+60 11-2345 6612" },
  { id: 7, name: "Rajesh Kumar a/l Muniandy", memberNo: "DEMO-0057", email: "rajesh.k@example.my", phone: "+60 14-667 3390" },
  { id: 8, name: "Siti Hajar binti Osman", memberNo: "DEMO-0088", email: "sitihajar@example.my", phone: "+60 13-889 0021" },
  { id: 9, name: "Muhammad Haziq bin Azman", memberNo: "DEMO-0142", email: "haziq.azman@example.my", phone: "+60 12-345 6789" },
  { id: 10, name: "Kavitha a/p Ramasamy", memberNo: "DEMO-0151", email: "kavitha.r@example.my", phone: "+60 16-210 9987" },
  { id: 11, name: "Chong Mei Yee", memberNo: "DEMO-0163", email: "meiyee.chong@example.my", phone: "+60 12-778 4410" },
  { id: 12, name: "Faridah binti Ismail", memberNo: "DEMO-0170", email: "faridah.i@example.my", phone: "+60 19-332 1180" },
];

export const fpxBanks = [
  "Maybank2u",
  "CIMB Clicks",
  "Public Bank",
  "RHB Now",
  "Hong Leong Connect",
  "Bank Islam",
  "AmOnline",
  "Bank Rakyat",
];
