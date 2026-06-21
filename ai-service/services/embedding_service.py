from openai import openAI

client = openAI();

def create_embedding(text):
    response = client.embeddings.create(
        model="text-embedding-3-small",
        input=text
    )

    return response.data[0].embedding